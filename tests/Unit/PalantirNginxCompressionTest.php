<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PalantirNginxCompressionTest extends TestCase
{
    public function test_json_negotiation_preserves_content_and_existing_asset_behavior(): void
    {
        $nginx = getenv('NGINX_BINARY');
        if (! $nginx || ! is_executable($nginx)) {
            $this->markTestSkipped('Set NGINX_BINARY to exercise the candidate with a real isolated Nginx listener.');
        }

        $directory = sys_get_temp_dir().'/palantir-nginx-test-'.bin2hex(random_bytes(8));
        mkdir($directory.'/build/assets', 0700, true);
        $json = json_encode(['records' => array_fill(0, 80, ['name' => 'Test project', 'amount' => 1234])]);
        $files = [
            'page.json' => $json,
            'small.json' => '{"ok":true}',
            'build/assets/test.js' => str_repeat('const value = 1;\n', 300),
            'build/assets/test.css' => str_repeat('body { color: blue; }\n', 300),
        ];
        foreach ($files as $path => $body) {
            file_put_contents($directory.'/'.$path, $body);
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $snippet = dirname(__DIR__, 2).'/scripts/palantir-nginx-assets.conf';
        $configuration = "daemon on; pid {$directory}/nginx.pid; error_log {$directory}/error.log;\n"
            ."events {} http { access_log off; server { listen {$address}; root {$directory};\n"
            ."types { application/json json; application/javascript js; text/css css; }\n"
            ."chunked_transfer_encoding off; include {$snippet}; } }\n";
        file_put_contents($directory.'/nginx.conf', $configuration);
        $command = [$nginx, '-p', $directory.'/', '-c', $directory.'/nginx.conf'];

        try {
            $this->runCommand([...$command, '-t']);
            $this->runCommand($command);
            foreach (['page.json', 'build/assets/test.js', 'build/assets/test.css'] as $path) {
                $compressed = $this->request($address, '/'.$path, 'gzip');
                $this->assertStringContainsString('200 ok', $compressed['headers']);
                $this->assertStringContainsString('content-encoding: gzip', $compressed['headers']);
                $this->assertMatchesRegularExpression('/vary:[^\r\n]*accept-encoding/', $compressed['headers']);
                $this->assertSame($files[$path], gzdecode($compressed['body']));
                $this->assertLessThan(strlen($files[$path]), strlen($compressed['body']));
                if (str_starts_with($path, 'build/')) {
                    $this->assertStringContainsString('public, max-age=31536000, immutable', $compressed['headers']);
                }
            }
            foreach (['identity', 'gzip;q=0, identity;q=1'] as $encoding) {
                $identity = $this->request($address, '/page.json', $encoding);
                $this->assertStringNotContainsString('content-encoding:', $identity['headers']);
                $this->assertSame($json, $identity['body']);
            }
            $small = $this->request($address, '/small.json', 'gzip');
            $this->assertStringNotContainsString('content-encoding:', $small['headers']);
            $this->assertSame($files['small.json'], $small['body']);
            $missing = $this->request($address, '/build/assets/missing.js', 'gzip');
            $this->assertStringContainsString('404 not found', $missing['headers']);
            $this->assertStringContainsString('public, max-age=31536000, immutable', $missing['headers']);
        } finally {
            if (is_file($directory.'/nginx.pid')) {
                $this->runCommand([...$command, '-s', 'quit']);
                for ($attempt = 0; $attempt < 50 && is_file($directory.'/nginx.pid'); $attempt++) {
                    usleep(20000);
                }
            }
            foreach ([...array_keys($files), 'nginx.conf', 'error.log'] as $path) {
                if (is_file($directory.'/'.$path)) {
                    unlink($directory.'/'.$path);
                }
            }
            rmdir($directory.'/build/assets');
            rmdir($directory.'/build');
            rmdir($directory);
        }
    }

    /** @return array{headers: string, body: string} */
    private function request(string $address, string $path, string $encoding): array
    {
        $socket = stream_socket_client('tcp://'.$address, timeout: 5);
        $this->assertNotFalse($socket);
        stream_set_timeout($socket, 5);
        fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: localhost\r\nAccept-Encoding: {$encoding}\r\nConnection: close\r\n\r\n");
        $response = stream_get_contents($socket);
        fclose($socket);
        [$headers, $body] = explode("\r\n\r\n", $response, 2);

        return ['headers' => strtolower($headers), 'body' => $body];
    }

    /** @param array<int, string> $command */
    private function runCommand(array $command): void
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output);
    }
}
