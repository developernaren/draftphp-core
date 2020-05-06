<?php

namespace DraftPhp\Responses;

use DraftPhp\Config;
use DraftPhp\Utils\Str;
use React\Filesystem\FilesystemInterface;
use React\Http\Response;
use React\Promise\PromiseInterface;

class Asset extends AbstractResponse
{
    protected $fileExtension;

    public function __construct(Config $config, FilesystemInterface $filesystem, string $path, string $fullPath)
    {

        parent::__construct($config, $filesystem, $path, $fullPath);
        $filename = $this->config->getBuildBaseFolder() . '/' . $path;

        $this->filename = $this->removeMultipleSlashes($filename);
        $this->fullPath = $fullPath;
        $this->filesystem = $filesystem;
        $this->fileExtension = (new Str($path))->getAfterLast('.');
    }

    public function toResponse(): PromiseInterface
    {
        $file = $this->filesystem->file($this->filename);

        return $file->exists()
            ->then(function () use ($file) {
                return $file->getContents()
                    ->then(function ($content) {
                        return new Response(200, array_merge($this->headers, $this->getMimeTypeHeader($this->fileExtension)), $content);
                    });
            }, function () {

                $assetsFolder = $this->config->getAssetsBaseFolder();
                $assetsImage = $this->removeMultipleSlashes($assetsFolder . '/' . $this->path);
                $imageFile = $this->filesystem->file($assetsImage);

                return $imageFile->exists()
                    ->then(function () use ($imageFile) {

                        $buildImagePath = $this->removeMultipleSlashes($this->config->getBuildBaseFolder() . '/' . $this->path);
                        $buildImageFolder = (new Str($buildImagePath))->removeAllAfterLast('/');
                        $directory = $this->filesystem->dir($buildImageFolder);

                        $directory->stat()
                            ->then(function () {
                                return '';
                            }, function () use ($directory) {
                                return $directory->createRecursive();
                            })->then(function () use ($imageFile, $buildImagePath) {
                                $imageFile->copy($this->filesystem->file($buildImagePath));
                            });

                        return $imageFile->getContents()
                            ->then(function ($content) {
                                return new Response(200, array_merge($this->headers, $this->getMimeTypeHeader($this->fileExtension)), $content);
                            });

                    }, $this->responseNotFound());
            });
    }
}