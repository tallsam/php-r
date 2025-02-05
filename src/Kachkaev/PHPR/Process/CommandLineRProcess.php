<?php
namespace Kachkaev\PHPR\Process;

use Kachkaev\PHPR\RError;
use Kachkaev\PHPR\Exception\RProcessException;

class CommandLineRProcess extends AbstractRProcess
{
    private $rCommand;
    private $process;

    private $pipes;

    private $sleepTimeBetweenReads = 1;
    private $infiniteLength = 100500;

    public function __construct($rCommand)
    {
        $this->rCommand = $rCommand;
    }

    function doStart()
    {
        $descriptors = array(0 => array("pipe", "r"), 1 => array("pipe", "w"),
                2 => array("pipe", "w"),);

        $this->process = proc_open(
		sprintf("\"%s\" --silent --vanilla", $this->rCommand),
		$descriptors, 
		$this->pipes
	);

        if (!is_resource($this->process)) {
            throw new RProcessException('Could not create the process');
        }

        stream_set_blocking($this->pipes[2], false);

        $errorOutput = fgets($this->pipes[2]);
        if ($errorOutput) {
            throw new RProcessException($errorOutput);
        }

        // Skip the startup message (if any)
        do {
            $out = fread($this->pipes[1], $this->infiniteLength);
            usleep($this->sleepTimeBetweenReads);
        } while ($out != '> '
                && substr($out, -3) != "\n> ");
    }

    function doStop()
    {
        fclose($this->pipes[0]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        proc_close($this->process);
    }

    function doWrite(array $rInputLines)
    {
        $currentCommandInput = '';
        $currentCommandOutput = '';
        $currentCommandErrorOutput = '';

        foreach ($rInputLines as $rInputLine) {
            ++$this->inputLineCount;

            // Write the input into the pipe
            fwrite($this->pipes[0], $rInputLine . "\n");

            // Read back the input
            $currentCommandInput .= fread($this->pipes[1],
                    $this->infiniteLength);
            $commandIsIncomplete = false;
            do {
                // Append the output
                $currentCommandOutput .= fread($this->pipes[1],
                        $this->infiniteLength);
                $currentCommandErrorOutput .= fread($this->pipes[2],
                        $this->infiniteLength);

                // If the output is "+ ", then it is a multi-line command
                if ($currentCommandOutput === '+ ') {
                    $commandIsIncomplete = true;
                    $currentCommandOutput = '';

                    // A multi-line command that does not finish is a fatal case
                    if ($rInputLine === end($rInputLines)) {
                        throw new IncompleteRCommandException($currentCommandInput);
                    }
                    break;
                }

                usleep($this->sleepTimeBetweenReads);
            } while ($currentCommandOutput != '> '
                    && substr($currentCommandOutput, -3) != "\n> ");

            // Continue reading input if it is a multi-line command
            if ($commandIsIncomplete) {
                continue;
            }

            // Trim "\n" from the command input
            $currentCommandInput = substr($currentCommandInput, 0, -1);
            // Trim "\n> " from the command output
            $currentCommandOutput = substr($currentCommandOutput, 0, -3);
            if ($currentCommandOutput === false) {
                $currentCommandOutput = null;
            }
            // Trim "\n" from the error input
            $currentCommandErrorOutput = substr($currentCommandErrorOutput, 0,
                    -1);

            // Add input and output to logs
            $this->inputLog[] = $currentCommandInput;
            $this->outputLog[] = $currentCommandOutput;
            ++$this->lastWriteCommandCount;

            // Register an error if needed
            if ($currentCommandErrorOutput) {
                $error = new RError($this->inputLineCount - 1, count($this->inputLog) - 1,
                        $currentCommandInput, $currentCommandErrorOutput);
                ++$this->lastWriteErrorCount;
                $this->errors[] = $error;
            }

            // Reset buffers
            $currentCommandInput = '';
            $currentCommandOutput = '';
            $currentCommandErrorOutput = '';
        }
    }
}
