<?php

namespace Kaliop\eZMigrationBundle\Core\Executor;

use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Process;
use Kaliop\eZMigrationBundle\API\Value\MigrationStep;
use Kaliop\eZMigrationBundle\Core\ReferenceResolver\PrefixBasedResolverInterface;

class ProcessExecutor extends AbstractExecutor
{
    protected $supportedStepTypes = array('process');
    protected $supportedActions = array('run');

    protected $defaultTimeout = 86400;

    /** @var PrefixBasedResolverInterface $referenceResolver */
    protected $referenceResolver;

    public function __construct(PrefixBasedResolverInterface $referenceResolver)
    {
        $this->referenceResolver = $referenceResolver;
    }

    /**
     * @param MigrationStep $step
     * @return mixed
     * @throws \Exception
     */
    public function execute(MigrationStep $step)
    {
        parent::execute($step);

        if (!isset($step->dsl['mode'])) {
            throw new \Exception("Invalid step definition: missing 'mode'");
        }

        $action = $step->dsl['mode'];

        if (!in_array($action, $this->supportedActions)) {
            throw new \Exception("Invalid step definition: value '$action' is not allowed for 'mode'");
        }

        return $this->$action($step->dsl, $step->context);
    }

    /**
     * @param $dsl
     * @param $context
     * @return \Symfony\Component\Process\Process
     * @throws \Exception
     * @todo add more options supported by Sf Process
     */
    protected function run($dsl, $context)
    {
        if (!isset($dsl['command'])) {
            throw new \Exception("Can not run process: command missing");
        }

        $builder = new ProcessBuilder();

        // mandatory args and options
        $builderArgs = array($this->referenceResolver->resolveReference($dsl['command']));

        if (isset($dsl['arguments'])) {
            foreach($dsl['arguments'] as $arg) {
                $builderArgs[] = $this->referenceResolver->resolveReference($arg);
            }
        }

        $process = $builder
            ->setArguments($builderArgs)
            ->getProcess();

        // allow long migrations processes by default
        $timeout = $this->defaultTimeout;
        if (isset($dsl['timeout'])) {
            $timeout = $dsl['timeout'];
        }
        $process->setTimeout($timeout);

        if (isset($dsl['working_directory'])) {
            $process->setWorkingDirectory($dsl['working_directory']);
        }

        if (isset($dsl['disable_output'])) {
            $process->disableOutput();
        }

        if (isset($dsl['environment'])) {
            $process->setEnv($dsl['environment']);
        }

        $process->run();

        $this->setReferences($process, $dsl);

        return $process;
    }

    protected function setReferences(Process $process, $dsl)
    {
        if (!array_key_exists('references', $dsl)) {
            return false;
        }


        foreach ($dsl['references'] as $reference) {
            switch ($reference['attribute']) {
                case 'error_output':
                    $value = $process->getErrorOutput();
                    break;
                case 'exit_code':
                    $value = $process->getExitCode();
                    break;
                case 'output':
                    $value = $process->getOutput();
                    break;
                default:
                    throw new \InvalidArgumentException('Process executor does not support setting references for attribute ' . $reference['attribute']);
            }

            $overwrite = false;
            if (isset($reference['overwrite'])) {
                $overwrite = $reference['overwrite'];
            }
            $this->referenceResolver->addReference($reference['identifier'], $value, $overwrite);
        }

        return true;
    }

    /**
     * Replaces any references inside a string
     *
     * @param string
     * @return string
     */
    protected function resolveReferencesInText($text)
    {
        // we need to alter the regexp we get from the resolver, as it will be used to match parts of text, not the whole string
        $regexp = substr($this->referenceResolver->getRegexp(), 1, -1);
        // NB: here we assume that all regexp resolvers give us a regexp with a very specific format...
        $regexp = '/\[' . preg_replace(array('/^\^/'), array('', ''), $regexp) . '[^]]+\]/';

        $count = preg_match_all($regexp, $text, $matches);
        // $matches[0][] will have the matched full string eg.: [reference:example_reference]
        if ($count) {
            foreach ($matches[0] as $referenceIdentifier) {
                $reference = $this->referenceResolver->getReferenceValue(substr($referenceIdentifier, 1, -1));
                $text = str_replace($referenceIdentifier, $reference, $text);
            }
        }

        return $text;
    }
}