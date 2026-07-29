<?php

namespace App\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Streams a subprocess's output line-by-line to a callback, as it happens.
 *
 * The N1 provisioner runs `Process::run()` and only sees output when the process
 * EXITS — right for a 5s step, useless for a multi-minute nix build (the
 * "building… + spinner, no signal" problem the N2 console exists to kill). This
 * wraps Laravel's Process with its chunk callback and does the one genuinely
 * fiddly part: reassembling whole lines from arbitrary chunk boundaries.
 *
 * Symfony hands us bytes, not lines: a single line can arrive split across two
 * chunks, and one chunk can carry several lines. So we buffer per stream (stdout
 * and stderr independently), emit only on a newline, and flush any trailing
 * partial line when the process ends.
 *
 * The stdout/stderr split matters here: kixctl-build puts its JSON result on
 * stdout and ALL build logs on stderr. Callers stream 'err' to the console and
 * keep the final 'out' as the result payload — so we keep the two streams
 * distinct and hand back the full ProcessResult, meaning exit code + complete
 * stdout are still available exactly as the blocking API gave them.
 */
class ConsoleStreamer
{
    /** @var array<string,string> partial-line carry per stream ('out'|'err') */
    private array $buffers = ['out' => '', 'err' => ''];

    /**
     * Run $command, invoking $onLine('out'|'err', $line) for each COMPLETE line
     * as it arrives, then flushing any unterminated tail. Returns the finished
     * ProcessResult so callers keep everything the blocking API gave them.
     *
     * @param  array<int,string>|string      $command
     * @param  callable(string,string):void  $onLine
     */
    public function run(array|string $command, callable $onLine, int $timeout = 1800): ProcessResult
    {
        $this->buffers = ['out' => '', 'err' => ''];

        $result = Process::timeout($timeout)->run(
            $command,
            function (string $type, string $chunk) use ($onLine): void {
                // Laravel passes 'out' or 'err' (Symfony Process::OUT / ERR).
                $stream = $type === 'err' ? 'err' : 'out';
                $this->ingest($stream, $chunk, $onLine);
            },
        );

        // Flush any trailing text with no final newline (the last line of output).
        foreach (['out', 'err'] as $stream) {
            $tail = $this->buffers[$stream];
            if ($tail !== '') {
                $onLine($stream, rtrim($tail, "\r"));
                $this->buffers[$stream] = '';
            }
        }

        return $result;
    }

    /**
     * Append a raw chunk to the stream's buffer, emit every complete line, and
     * keep the unterminated remainder for the next chunk. Strips a lone trailing
     * CR so CRLF / progress-redraw output doesn't leak \r into the console.
     */
    private function ingest(string $stream, string $chunk, callable $onLine): void
    {
        $buffer = $this->buffers[$stream].$chunk;

        // explode on \n; the final element is the (possibly empty) partial line
        // that we carry into the next chunk.
        $parts = explode("\n", $buffer);
        $this->buffers[$stream] = array_pop($parts);

        foreach ($parts as $line) {
            $onLine($stream, rtrim($line, "\r"));
        }
    }
}
