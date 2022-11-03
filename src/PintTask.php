<?php

declare(strict_types=1);

namespace HT\GrumPhpPintTask;

use GrumPHP\Collection\FilesCollection;
use GrumPHP\Collection\ProcessArgumentsCollection;
use GrumPHP\Runner\TaskResult;
use GrumPHP\Runner\TaskResultInterface;
use GrumPHP\Task\AbstractExternalTask;
use GrumPHP\Task\Context\ContextInterface;
use GrumPHP\Task\Context\GitPreCommitContext;
use GrumPHP\Task\Context\RunContext;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PintTask extends AbstractExternalTask
{
    public static function getConfigurableOptions(): OptionsResolver
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'files_on_pre_commit' => false,
            'paths' => [],
        ]);

        $resolver->addAllowedTypes('files_on_pre_commit', ['bool']);
        $resolver->addAllowedTypes('paths', ['array']);

        return $resolver;
    }

    public function canRunInContext(ContextInterface $context): bool
    {
        return $context instanceof GitPreCommitContext || $context instanceof RunContext;
    }

    public function run(ContextInterface $context): TaskResultInterface
    {
        assert($context instanceof GitPreCommitContext || $context instanceof RunContext);
        $config = $this->getConfig()->getOptions();
        $files = $context->getFiles()->extensions(['php']);
        if (0 === \count($files) && $config['files_on_pre_commit']) {
            return TaskResult::createSkipped($this, $context);
        }

        $arguments = $this->processBuilder->createArgumentsForCommand('pint');
        $arguments->add('--test');
        $this->addPaths($arguments, $context, $files, $this->getConfig()->getOptions());
        $process = $this->processBuilder->buildProcess($arguments);
        $process->run();

        if (! $process->isSuccessful()) {
            return TaskResult::createFailed($this, $context, sprintf(
                "Your %s contains files that should pass Laravel Pint but do not:%s%s%s%s%s",
                $config['files_on_pre_commit'] ? 'commit' : 'paths',
                PHP_EOL,
                $this->formatter->format($process),
                PHP_EOL,
                PHP_EOL,
                'Please fix the Laravel Pint errors by `./vendor/bin/pint {PATH}` and try again.'
            ));
        }

        return TaskResult::createPassed($this, $context);
    }

    /**
     * This method adds the newly committed files in pre commit context if you enabled the files_on_pre_commit flag.
     * In other cases, it falls back to the configured paths.
     * If no paths have been set, the paths from inside your rector configuration file will be used.
     */
    private function addPaths(
        ProcessArgumentsCollection $arguments,
        ContextInterface $context,
        FilesCollection $files,
        array $config
    ): void {
        if ($context instanceof GitPreCommitContext && $config['files_on_pre_commit']) {
            $arguments->addFiles($files);

            return;
        }

        $arguments->addArgumentArray('%s', $config['paths']);
    }
}
