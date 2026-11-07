<?php
declare(strict_types=1);

namespace MiniXDebug\Service;

/**
 * DBGp のエンジン側（本物の Xdebug と同じ立場）
 *
 * - IDE が listen していて、こちらから接続しに行く
 * - IDE → こちら: `コマンド -i 取引ID -x 値 ... -- base64データ\0`
 * - こちら → IDE: `XMLの長さ\0XML\0`
 */
final class Dbgp
{
    private const NS = 'xmlns="urn:debugger_protocol_v1" xmlns:xdebug="https://xdebug.org/dbgp/xdebug"';

    /** @var array<int, array{line: int, state: string, requested: int}> IDE から受け取ったブレークポイント */
    private array $breakpoints = [];
    private int $nextBreakpointId = 1;

    /** run / step_* の応答は、次に止まったとき（または終わったとき）に返す */
    private ?array $pending = null; // [command, transaction_id]

    private string $buffer = '';

    private int $maxChildren = 32;
    private int $maxDepth = 1;
    private int $maxData = 1024;

    /** @param resource $socket */
    private function __construct(private $socket, private string $file) {}

    public static function connect(string $address, string $file): self
    {
        $socket = @stream_socket_client("tcp://$address", $errno, $errstr, 3);
        if ($socket === false) {
            throw new \RuntimeException("DBGp: $address に接続できません（{$errstr}）。VS Code で「Listen for Xdebug」を開始していますか？");
        }
        $self = new self($socket, $file);
        $self->send(sprintf(
            '<init %s fileuri="%s" language="PHP" xdebug:language_version="%s" protocol_version="1.0" appid="%d" idekey="minixdebug">'
            . '<engine version="0.1"><![CDATA[MiniXdebug]]></engine></init>',
            self::NS,
            self::attr(self::uri($file)),
            PHP_VERSION,
            getmypid(),
        ));
        return $self;
    }

    /** 実行開始前。ブレークポイントなどを受け取り、run / step_into が来たら戻る */
    public function handshake(): void
    {
        $this->loop();
    }

    /** __dbg で止まったとき */
    public function onBreak(): void
    {
        $this->respondPending('break', $this->locationXml());
        $this->loop();
    }

    /** 対象スクリプトが最後まで実行されたとき */
    public function onFinish(): void
    {
        $this->respondPending('stopping');
        // IDE からの stop / detach を待つ（ほかのコマンドにも応答しておく）
        $this->loop();
    }

    // ------------------------------------------------------------------
    // コマンドのループ

    /** run / step_* を受け取るまでコマンドを処理する */
    private function loop(): void
    {
        while (true) {
            $line = $this->readCommand();
            if ($line === null) {
                $this->disconnected();
                return;
            }
            [$cmd, $args, $data] = self::parseCommand($line);
            $txn = $args['i'] ?? '0';

            if (in_array($cmd, ['run', 'step_into', 'step_over', 'step_out'], true)) {
                $this->pending = [$cmd, $txn];
                Debugger::resume(match ($cmd) {
                    'run'       => 'run',
                    'step_into' => 'step',
                    'step_over' => 'next',
                    'step_out'  => 'out',
                });
                return;
            }
            if ($cmd === 'stop' || $cmd === 'detach') {
                $this->respond($cmd, $txn, 'status="stopped" reason="ok"');
                fclose($this->socket);
                Debugger::$dbgp = null;
                if ($cmd === 'stop') {
                    exit(0); // 一時ファイルはシャットダウン関数で消える
                }
                Debugger::resume('run'); // detach: デバッガを外して最後まで実行する
                Debugger::$breakpoints = [];
                return;
            }

            try {
                $this->handle($cmd, $txn, $args, $data);
            } catch (DbgpError $e) {
                $this->send(sprintf(
                    '<response %s command="%s" transaction_id="%s"><error code="%d"><message><![CDATA[%s]]></message></error></response>',
                    self::NS, self::attr($cmd), self::attr($txn), $e->getCode(), $e->getMessage(),
                ));
            }
        }
    }

    private function handle(string $cmd, string $txn, array $args, ?string $data): void
    {
        switch ($cmd) {
            case 'status':
                $this->respond($cmd, $txn, 'status="' . ($this->pending === null ? 'starting' : 'break') . '" reason="ok"');
                return;

            case 'feature_get':
                $name = $args['n'] ?? '';
                $value = match ($name) {
                    'language_name'     => 'PHP',
                    'language_version'  => PHP_VERSION,
                    'protocol_version'  => '1',
                    'max_children'      => (string) $this->maxChildren,
                    'max_depth'         => (string) $this->maxDepth,
                    'max_data'          => (string) $this->maxData,
                    'encoding'          => 'UTF-8',
                    'supports_async'    => '0',
                    'breakpoint_types'  => 'line',
                    // 1 にすると、IDE は breakpoint_set の後に breakpoint_get でずらした行を取りに来る
                    'resolved_breakpoints' => '1',
                    default             => null,
                };
                $this->respond($cmd, $txn, sprintf('feature="%s" supported="%d"', self::attr($name), $value !== null),
                    $value !== null ? "<![CDATA[$value]]>" : '');
                return;

            case 'feature_set':
                $name = $args['n'] ?? '';
                $value = $args['v'] ?? '';
                match ($name) {
                    'max_children' => $this->maxChildren = max(1, (int) $value),
                    'max_depth'    => $this->maxDepth = max(0, (int) $value),
                    'max_data'     => $this->maxData = (int) $value,
                    default        => null, // ほかの設定は受け付けたことにする
                };
                $this->respond($cmd, $txn, sprintf('feature="%s" success="1"', self::attr($name)));
                return;

            case 'stdout':
            case 'stderr':
                // 出力のリダイレクトは未対応。対象スクリプトの出力はそのままターミナルに出る
                $this->respond($cmd, $txn, 'success="0"');
                return;

            case 'breakpoint_set':
                $this->breakpointSet($txn, $args);
                return;

            case 'breakpoint_get':
                $bp = $this->breakpoint($args['d'] ?? '');
                $this->respond($cmd, $txn, '', $this->breakpointXml((int) $args['d'], $bp));
                return;

            case 'breakpoint_list':
                $xml = '';
                foreach ($this->breakpoints as $id => $bp) {
                    $xml .= $this->breakpointXml($id, $bp);
                }
                $this->respond($cmd, $txn, '', $xml);
                return;

            case 'breakpoint_update':
                $id = (int) ($args['d'] ?? 0);
                $this->breakpoint((string) $id);
                if (isset($args['s'])) {
                    $this->breakpoints[$id]['state'] = $args['s'];
                }
                if (isset($args['n'])) {
                    $this->breakpoints[$id]['line'] = Debugger::resolveBreakpointLine((int) $args['n']) ?? (int) $args['n'];
                }
                $this->syncBreakpoints();
                $this->respond($cmd, $txn);
                return;

            case 'breakpoint_remove':
                $id = (int) ($args['d'] ?? 0);
                $this->breakpoint((string) $id);
                unset($this->breakpoints[$id]);
                $this->syncBreakpoints();
                $this->respond($cmd, $txn);
                return;

            case 'stack_depth':
                $this->respond($cmd, $txn, sprintf('depth="%d"', count(Debugger::$frames)));
                return;

            case 'stack_get':
                $xml = '';
                foreach ($this->stack() as $level => [$name, $line]) {
                    if (isset($args['d']) && (int) $args['d'] !== $level) {
                        continue;
                    }
                    $xml .= sprintf('<stack where="%s" level="%d" type="file" filename="%s" lineno="%d"/>',
                        self::attr($name), $level, self::attr(self::uri($this->file)), $line);
                }
                $this->respond($cmd, $txn, '', $xml);
                return;

            case 'context_names':
                // VS Code の REPL / ウォッチは2つ目のコンテキストを前提にしているので、空の Superglobals も返す
                $this->respond($cmd, $txn, '', '<context name="Locals" id="0"/><context name="Superglobals" id="1"/>');
                return;

            case 'context_get':
                $context = (int) ($args['c'] ?? 0);
                $xml = '';
                if ($context === 0) {
                    foreach ($this->vars((int) ($args['d'] ?? 0)) as $name => $value) {
                        $xml .= $this->propertyXml('$' . $name, '$' . $name, $value, 0, 0);
                    }
                }
                $this->respond($cmd, $txn, sprintf('context="%d"', $context), $xml);
                return;

            case 'property_get':
            case 'property_value':
                $fullname = $args['n'] ?? '';
                $value = $this->resolve($fullname, (int) ($args['d'] ?? 0));
                $page = (int) ($args['p'] ?? 0);
                $name = self::shortName($fullname);
                $this->respond($cmd, $txn, '', $this->propertyXml($name, $fullname, $value, 0, $page));
                return;

            case 'eval':
                // 式の評価はできないので、変数の参照（$x, $a['k'], $o->p）だけ受け付ける
                $expr = trim((string) $data);
                $value = $this->resolve($expr, 0);
                $this->respond($cmd, $txn, '', $this->propertyXml($expr, $expr, $value, 0, 0));
                return;

            case 'source':
                $begin = (int) ($args['b'] ?? 1);
                $end = (int) ($args['e'] ?? count(Debugger::$lines));
                $src = implode('', array_slice(Debugger::$lines, $begin - 1, $end - $begin + 1));
                $this->respond($cmd, $txn, 'success="1" encoding="base64"', '<![CDATA[' . base64_encode($src) . ']]>');
                return;

            default:
                throw new DbgpError("unimplemented command: $cmd", 4);
        }
    }

    // ------------------------------------------------------------------
    // ブレークポイント

    private function breakpointSet(string $txn, array $args): void
    {
        $type = $args['t'] ?? 'line';
        $id = $this->nextBreakpointId++;

        if ($type !== 'line') {
            // exception / call / conditional などは未対応。IDE 側を止めないように id だけ返す
            $this->respond('breakpoint_set', $txn, sprintf('id="%d" state="disabled"', $id));
            return;
        }
        if (isset($args['f']) && self::path($args['f']) !== $this->file) {
            // 対象スクリプト以外のファイル（単一ファイル限定）
            $this->respond('breakpoint_set', $txn, sprintf('id="%d" resolved="unresolved"', $id));
            return;
        }

        $requested = (int) ($args['n'] ?? 0);
        // } だけの行などは、その行以降で最初に止まれる行へずらす
        $line = Debugger::resolveBreakpointLine($requested);
        if ($line === null) {
            $this->respond('breakpoint_set', $txn, sprintf('id="%d" resolved="unresolved"', $id));
            return;
        }
        $this->breakpoints[$id] = ['line' => $line, 'state' => $args['s'] ?? 'enabled', 'requested' => $requested];
        $this->syncBreakpoints();

        // ずらした行は、IDE が続けて送ってくる breakpoint_get の応答（lineno）で伝わり、VS Code 側の表示が移動する
        $this->respond('breakpoint_set', $txn, sprintf('id="%d" resolved="resolved"', $id));
    }

    private function breakpointXml(int $id, array $bp): string
    {
        return sprintf('<breakpoint type="line" id="%d" state="%s" resolved="resolved" filename="%s" lineno="%d" hit_count="0" hit_value="0"/>',
            $id, self::attr($bp['state']), self::attr(self::uri($this->file)), $bp['line']);
    }

    private function breakpoint(string $id): array
    {
        return $this->breakpoints[(int) $id] ?? throw new DbgpError("no such breakpoint: $id", 205);
    }

    /** IDE のブレークポイントを Debugger 側の [line => true] に反映する */
    private function syncBreakpoints(): void
    {
        Debugger::$breakpoints = [];
        foreach ($this->breakpoints as $bp) {
            if ($bp['state'] === 'enabled') {
                Debugger::$breakpoints[$bp['line']] = true;
            }
        }
    }

    // ------------------------------------------------------------------
    // スタックと変数

    /** @return list<array{string, int}> level 0 が一番内側 */
    private function stack(): array
    {
        return array_map(static fn(array $f) => [$f[0], $f[1]], array_reverse(Debugger::$frames));
    }

    private function vars(int $depth): array
    {
        $frames = array_reverse(Debugger::$frames);
        if (!isset($frames[$depth])) {
            throw new DbgpError("stack depth invalid: $depth", 301);
        }
        return $frames[$depth][2];
    }

    /** `$items['a']` や `$o->p[0]` のような fullname をたどって値を取り出す */
    private function resolve(string $fullname, int $depth): mixed
    {
        if (!preg_match('/^\$(\w+)(.*)$/s', $fullname, $m)) {
            throw new DbgpError("can not get property: $fullname", 300);
        }
        $vars = $this->vars($depth);
        if (!array_key_exists($m[1], $vars)) {
            throw new DbgpError("can not get property: $fullname", 300);
        }
        $value = $vars[$m[1]];
        $rest = $m[2];

        while ($rest !== '') {
            if (preg_match('/^\[(\d+)\](.*)$/s', $rest, $m)) {
                $key = (int) $m[1];
            } elseif (preg_match('/^\[\'((?:[^\'\\\\]|\\\\.)*)\'\](.*)$/s', $rest, $m)
                || preg_match('/^\["((?:[^"\\\\]|\\\\.)*)"\](.*)$/s', $rest, $m)) {
                $key = stripslashes($m[1]);
            } elseif (preg_match('/^->(\w+)(.*)$/s', $rest, $m)) {
                $props = self::objectProps($value);
                if (!isset($props[$m[1]])) {
                    throw new DbgpError("can not get property: $fullname", 300);
                }
                $value = $props[$m[1]][1];
                $rest = $m[2];
                continue;
            } else {
                throw new DbgpError("can not get property: $fullname", 300);
            }
            if (!is_array($value) || !array_key_exists($key, $value)) {
                throw new DbgpError("can not get property: $fullname", 300);
            }
            $value = $value[$key];
            $rest = $m[2];
        }
        return $value;
    }

    /** 変数1つを <property> にする。配列・オブジェクトは max_depth まで子も入れる */
    private function propertyXml(string $name, string $fullname, mixed $value, int $level, int $page): string
    {
        $attrs = sprintf('name="%s" fullname="%s"', self::attr($name), self::attr($fullname));

        if (is_array($value) || is_object($value)) {
            $children = [];
            if (is_array($value)) {
                $type = 'type="array"';
                foreach ($value as $k => $v) {
                    $childFull = $fullname . (is_int($k) ? "[$k]" : "['" . addcslashes($k, "'\\") . "']");
                    $children[] = [(string) $k, $childFull, $v, ''];
                }
            } else {
                $type = sprintf('type="object" classname="%s"', self::attr($value::class));
                foreach (self::objectProps($value) as $prop => [$facet, $v]) {
                    $children[] = [$prop, "$fullname->$prop", $v, $facet];
                }
            }
            $count = count($children);
            $xml = sprintf('<property %s %s children="%d" numchildren="%d" page="%d" pagesize="%d">',
                $attrs, $type, $count > 0, $count, $page, $this->maxChildren);
            if ($level < $this->maxDepth) {
                foreach (array_slice($children, $page * $this->maxChildren, $this->maxChildren) as [$n, $f, $v, $facet]) {
                    $child = $this->propertyXml($n, $f, $v, $level + 1, 0);
                    if ($facet !== '') {
                        $child = preg_replace('/^<property /', '<property facet="' . $facet . '" ', $child);
                    }
                    $xml .= $child;
                }
            }
            return $xml . '</property>';
        }

        return match (true) {
            $value === null    => "<property $attrs type=\"null\"></property>",
            is_bool($value)    => "<property $attrs type=\"bool\"><![CDATA[" . (int) $value . ']]></property>',
            is_int($value)     => "<property $attrs type=\"int\"><![CDATA[$value]]></property>",
            is_float($value)   => "<property $attrs type=\"float\"><![CDATA[$value]]></property>",
            is_string($value)  => sprintf('<property %s type="string" size="%d" encoding="base64"><![CDATA[%s]]></property>',
                $attrs, strlen($value), base64_encode($this->maxData > 0 ? substr($value, 0, $this->maxData) : $value)),
            default            => "<property $attrs type=\"resource\"><![CDATA[" . get_resource_type($value) . ']]></property>',
        };
    }

    /** @return array<string, array{string, mixed}> [プロパティ名 => [facet, 値]] */
    private static function objectProps(mixed $object): array
    {
        if (!is_object($object)) {
            return [];
        }
        $props = [];
        foreach ((array) $object as $key => $v) {
            // (array) キャストすると private は "\0クラス名\0名前"、protected は "\0*\0名前" になる
            $parts = explode("\0", (string) $key);
            if (count($parts) === 3) {
                $props[$parts[2]] = [$parts[1] === '*' ? 'protected' : 'private', $v];
            } else {
                $props[(string) $key] = ['public', $v];
            }
        }
        return $props;
    }

    private static function shortName(string $fullname): string
    {
        if (preg_match('/(?:\[\'?((?:[^\'\\\\]|\\\\.)*?)\'?\]|->(\w+))$/', $fullname, $m)) {
            return ($m[2] ?? '') !== '' ? $m[2] : stripslashes($m[1]);
        }
        return $fullname;
    }

    private function locationXml(): string
    {
        $line = end(Debugger::$frames)[1];
        return sprintf('<xdebug:message filename="%s" lineno="%d"></xdebug:message>', self::attr(self::uri($this->file)), $line);
    }

    // ------------------------------------------------------------------
    // 送受信

    private function respondPending(string $status, string $body = ''): void
    {
        if ($this->pending === null) {
            return;
        }
        [$cmd, $txn] = $this->pending;
        $this->pending = null;
        $this->respond($cmd, $txn, sprintf('status="%s" reason="ok"', $status), $body);
    }

    private function respond(string $cmd, string $txn, string $attrs = '', string $body = ''): void
    {
        $this->send(sprintf('<response %s command="%s" transaction_id="%s"%s>%s</response>',
            self::NS, self::attr($cmd), self::attr($txn), $attrs !== '' ? " $attrs" : '', $body));
    }

    private function send(string $xml): void
    {
        // VS Code の拡張は空白もテキストノードとして読むので、要素の間には改行や空白を入れない
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . $xml;
        if (getenv('MINIXDEBUG_LOG')) {
            file_put_contents(getenv('MINIXDEBUG_LOG'), "<- $xml\n", FILE_APPEND);
        }
        @fwrite($this->socket, strlen($xml) . "\0" . $xml . "\0");
    }

    /** \0 で区切られたコマンドを1つ読む。接続が切れたら null */
    private function readCommand(): ?string
    {
        while (($pos = strpos($this->buffer, "\0")) === false) {
            $r = [$this->socket];
            $w = $e = null;
            // Ctrl+C（SIGINT）のハンドラが動けるように、select で待つ
            if (@stream_select($r, $w, $e, null) === false) {
                continue;
            }
            $chunk = fread($this->socket, 8192);
            if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                return null;
            }
            $this->buffer .= $chunk;
        }
        $line = substr($this->buffer, 0, $pos);
        $this->buffer = substr($this->buffer, $pos + 1);
        if (getenv('MINIXDEBUG_LOG')) {
            file_put_contents(getenv('MINIXDEBUG_LOG'), "-> $line\n", FILE_APPEND);
        }
        return $line;
    }

    /** IDE が接続を切ったら、デバッガを外して最後まで実行する */
    private function disconnected(): void
    {
        @fclose($this->socket);
        Debugger::$dbgp = null;
        Debugger::$breakpoints = [];
        Debugger::resume('run');
    }

    /**
     * `breakpoint_set -i 3 -t line -f "file:///a b.php" -n 5 -- base64` を
     * ['breakpoint_set', ['i' => '3', 't' => 'line', ...], デコード済みデータ] にする
     *
     * @return array{string, array<string, string>, ?string}
     */
    public static function parseCommand(string $line): array
    {
        $data = null;
        if (($p = strpos($line, ' -- ')) !== false) {
            $data = base64_decode(substr($line, $p + 4));
            $line = substr($line, 0, $p);
        }
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|(\S+)/', $line, $m, PREG_SET_ORDER);
        $tokens = array_map(static fn($t) => isset($t[2]) ? $t[2] : stripcslashes($t[1]), $m);

        $cmd = array_shift($tokens) ?? '';
        $args = [];
        while ($tokens) {
            $opt = array_shift($tokens);
            if (str_starts_with($opt, '-')) {
                $args[substr($opt, 1)] = array_shift($tokens) ?? '';
            }
        }
        return [$cmd, $args, $data];
    }

    private static function uri(string $path): string
    {
        return 'file://' . implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function path(string $uri): string
    {
        $path = rawurldecode(preg_replace('#^file://#', '', $uri));
        return realpath($path) ?: $path;
    }

    private static function attr(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
