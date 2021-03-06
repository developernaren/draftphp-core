<?php

namespace DraftPhp\Commands;

use DraftPhp\BuildFileResolver;
use DraftPhp\HtmlGenerator;
use DraftPhp\SiteGenerator;
use DraftPhp\Utils\Str;
use DraftPhp\Watcher\FileChange;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Factory;
use React\Filesystem\Filesystem;
use React\Filesystem\FilesystemInterface;
use React\Http\Response;
use React\Http\Server;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use DraftPhp\Config;
use Symfony\Component\Finder\Finder;
use Yosymfony\ResourceWatcher\Crc32ContentHash;
use Yosymfony\ResourceWatcher\ResourceWatcher;
use Yosymfony\ResourceWatcher\ResourceCachePhpFile;
use function Clue\React\Block\await;
use function Clue\React\Block\awaitAll;
use DraftPhp\Responses\Factory as ResponseFactory;

class Watch extends Command
{
    protected static $defaultName = 'dev';
    private $message;
    private $config;
    private $filesystem;
    private $io;
    private $loop;
    private $watcher;
    private $changedFiles = [];
    public static $contentHashMap = [];

    public function __construct(Config $config)
    {
        parent::__construct();
        $this->config = $config;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $port = $this->config->getPort();
        $this->io = $io = new SymfonyStyle($input, $output);
        $this->message = new FileChange($io, $this->config);;
        $this->loop = Factory::create();
        $this->filesystem = Filesystem::create($this->loop);

        $folderWatching = [$this->config->getPageBaseFolder()];
        $this->io->title('Watching for file changes');
        $this->io->table(['watching folder'] , [[implode(' and ' , $folderWatching)]]);

        $finder = new Finder();
        $finder->files()
            ->name('*.html')
            ->name('*.md')
            ->in($folderWatching);

        $hashContent = new Crc32ContentHash();
        $resourceCache = new ResourceCachePhpFile(getBaseDir() . '/path-cache-file.php');
        $this->watcher = new ResourceWatcher($resourceCache, $finder, $hashContent);
        $this->watcher->initialize();

        $siteGenerator = new SiteGenerator($this->config, $this->filesystem, $this->loop);
        $siteGenerator->build();

        $content = $this->getWatchJs('http://localhost:' .$port)  . file_get_contents($this->config->getBuildBaseFolder() . '/index.html');
        file_put_contents($this->config->getBuildBaseFolder() . '/index.html', $content);

        $this->loop->addPeriodicTimer(1, function () {
            $result = $this->watcher->findChanges();
            $this->changedFiles = $changedFiles = $result->getUpdatedFiles();
            foreach ($changedFiles as $file) {
                $generator = new HtmlGenerator($this->config, $this->filesystem, $file);
                $this->message->notifyFileChange($file);
                $generator->getHtml()->then(function ($content) use ($file) {
                    $buildFile = (new BuildFileResolver($this->config, $file))->getName();
                    $path = str_replace($this->config->getBuildBaseFolder(), '', (new Str($buildFile))->removeLast('/index.html'));

                    unset(self::$contentHashMap[$path]);
                    $this->message->notifyFileChange($file);
                    file_put_contents($buildFile, $content);
                    $this->io->text(sprintf('%s build', $buildFile));
                });
            }
        });

        $server = new Server(function (ServerRequestInterface $request) use (&$firstBuild) {

            $path = $request->getUri()->getPath();
            $fullPath = $request->getUri()->__toString();

            return ResponseFactory::create($this->config, $this->filesystem, $path, $fullPath)
                ->toResponse();
        });

        $this->openBrowser($port);

        $socket = new \React\Socket\Server('127.0.0.1:' . $port, $this->loop);
        $server->listen($socket);

        $this->loop->run();

        return 0;
    }

    private function getWatchJs($path): string
    {
        $js = <<<HTML
<script>
function watch() {
    fetch('${path}',{
        mode: 'cors',
        headers :{
            'Content-Type' : 'text/plain',
        },
        cache : 'no-cache',
    })
        .then(function (response) {
            return response.text();
        }).then(function (html) {
            if(html){
                const page = document.getElementsByTagName('html')[0]
                page.innerHTML = html;
            }

    })
}
setInterval(watch, 1000);
</script>
HTML;
        return $js;
    }

    private function getContentHash($content): string
    {
        return hash('crc32', $content);
    }

    public function openBrowser($port)
    {
        switch (PHP_OS_FAMILY) {
            case 'Linux':
                exec('xdg-open http://localhost:' . $port);
                break;
            case 'Windows':
                exec('start http://localhost:' . $port);
                break;
            default:
                exec('open http://localhost:' . $port);
        }

    }
}
