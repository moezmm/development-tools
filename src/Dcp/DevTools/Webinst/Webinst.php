<?php

namespace Dcp\DevTools\Webinst;

use Dcp\DevTools\Utils\ConfigFile;

class Webinst
{
    protected $inputPath;
    protected $conf;
    protected $templateEngine;

    private $WEBINST_EXEC_MASK = 0100;

    public function __construct($inputPath)
    {
        if (!is_dir($inputPath)) {
            throw new Exception("The input path doesn't exist ($inputPath)");
        }
        $this->inputPath = $inputPath;

        $this->templateEngine = new \Mustache_Engine();

        $config = new ConfigFile($inputPath);

        $this->conf = $config->getConfig();

        if (!isset($this->conf["moduleName"])) {
            throw new Exception(
                sprintf(
                    "%s doesn't not contain the module name.",
                    $config->getConfigFilePath()
                )
            );
        }
        if (!isset($this->conf["version"])) {
            throw new Exception(
                sprintf(
                    "%s does not contains the version.",
                    $config->getConfigFilePath()
                )
            );
        }
        if (!isset($this->conf["release"])) {
            throw new Exception(
                sprintf(
                    "%s does not contains the release.",
                    $config->getConfigFilePath()
                )
            );
        }
    }

    public function setConfProperty($property, $value)
    {
        $this->conf[$property] = $value;
    }

    public function getConf($property=null)
    {
        if (is_null($property)) {
            return array_merge($this->conf);
        }
        if (isset($this->conf[$property])) {
            return $this->conf[$property];
        }
        return null;
    }

    public function makeWebinst($outputPath)
    {
        $contentTar = $this->inputPath . DIRECTORY_SEPARATOR . "temp_tar";
        $pharTar = new \PharData($contentTar . ".tar");
        $pharTar->startBuffering();
        if (isset($this->conf["application"]) && is_array($this->conf["application"])) {
            foreach ($this->conf["application"] as $applicationName) {
                $this->addApplication($pharTar, $applicationName);
            }
        }
        if (isset($this->conf["includedPath"]) && is_array($this->conf["includedPath"])) {
            foreach ($this->conf["includedPath"] as $includedPath) {
                $includedFullPath = $this->inputPath . DIRECTORY_SEPARATOR . $includedPath;
                if (is_dir($includedFullPath)) {
                    $this->addDirectory($pharTar, $includedFullPath);
                } elseif (is_file($includedFullPath)) {
                    $this->addFile($pharTar, $includedFullPath);
                }
            }
        }
        $pharTar->stopBuffering();
        $pharTar->compress(\Phar::GZ);
        unset($pharTar);
        unlink($contentTar.".tar");
        $infoXML = $this->templateEngine->render(
            '{{=@ @=}}' . file_get_contents(
                $this->inputPath . DIRECTORY_SEPARATOR . "info.xml"
            ), $this->conf
        );
        $webinstName = $this->getWebinstName();
        $pharTar = new \PharData($this->inputPath . DIRECTORY_SEPARATOR . $this->conf["moduleName"].".tar");
        $pharTar->startBuffering();
        $pharTar->addFromString("info.xml", $infoXML);
        if (file_exists($this->inputPath.DIRECTORY_SEPARATOR."LICENSE")) {
            $pharTar->addFile($this->inputPath.DIRECTORY_SEPARATOR."LICENSE", "LICENSE");
        }
        $pharTar->addFile($contentTar.".tar.gz", "content.tar.gz");
        $pharTar->stopBuffering();
        $pharTar->compress(\Phar::GZ);
        if (!$outputPath) {
            $outputPath = $this->inputPath;
        }
        rename($this->inputPath . DIRECTORY_SEPARATOR . $this->conf["moduleName"].".tar.gz",
            $outputPath . DIRECTORY_SEPARATOR . $webinstName . ".webinst");
        unlink($contentTar . ".tar.gz");
        unset($pharTar);
        unlink($this->inputPath . DIRECTORY_SEPARATOR . $this->conf["moduleName"] . ".tar");

        return $outputPath . DIRECTORY_SEPARATOR . $webinstName . ".webinst";
    }

    /**
     * @return string
     */
    public function getWebinstName()
    {
        return $this->templateEngine->render(
            "{{moduleName}}-{{version}}-{{release}}", $this->conf
        );
    }

    /**
     * @param \PharData $pharTar
     * @param $directory
     *
     * @return array files added
     */
    protected function addDirectory(\PharData $pharTar, $directory)
    {
        $addedFiles = $pharTar->buildFromIterator(
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $directory,
                    \FilesystemIterator::SKIP_DOTS
                )
            ),
            $this->inputPath
        );
        $this->setFlags($pharTar, $addedFiles);
        return $addedFiles;
    }

    /**
     * @param \PharData $pharTar
     * @param $filePath
     * @param $localName
     * @return array files added
     * @internal param $directory
     *
     */
    protected function addFile(\PharData $pharTar, $filePath, $localName = null)
    {
        if (null === $localName) {
            $localName = str_replace($this->inputPath, '', $filePath, $localName);
        }
        $pharTar->addFile($filePath, $localName);
        $addedFiles = [
            $localName, $filePath
        ];
        $this->setFlags($pharTar, $addedFiles);
        return $addedFiles;
    }

    /**
     * @param \PharData $pharTar
     * @param $applicationName
     *
     * @return array files added
     */
    protected function addApplication(\PharData $pharTar, $applicationName)
    {
        $addedFiles = $this->addDirectory(
            $pharTar, $this->inputPath . DIRECTORY_SEPARATOR . $applicationName
        );

        //inject variables into application_init.php file
        $appParamFile = $applicationName . DIRECTORY_SEPARATOR
            . $applicationName . "_init.php";
        $appParamContent = $this->templateEngine->render(
            '{{=@ @=}}' . file_get_contents(
                $this->inputPath . DIRECTORY_SEPARATOR . $appParamFile
            ), $this->conf
        );
        $pharTar->addFromString($appParamFile, $appParamContent);

        return $addedFiles;
    }

    /**
     * @param \PharData $pharTar
     * @param $addedFiles
     */
    protected function setFlags(\PharData $pharTar, $addedFiles)
    {
        foreach ($addedFiles as $pharFilePath => $systemFilePath) {
            if (!is_dir($systemFilePath)) {
                if (isset($this->conf['permission_masks'])) {
                    if (!empty($this->conf['permission_masks'][$pharFilePath])) {
                        $mask = $this->conf['permission_masks'][$pharFilePath];
                        $oldPerms = $pharTar[$pharFilePath]->getPerms();
                        $newPerms = $oldPerms | $mask;
                        echo sprintf("applying mask %s to %s (%s => %s) \n",
                            $mask,
                            $pharFilePath,
                            substr(sprintf('%o', $oldPerms), -4),
                            substr(sprintf('%o', $newPerms), -4)
                        );
                        $pharTar[$pharFilePath]->chmod($newPerms);
                    }
                } else {
                    if (is_executable($systemFilePath)) {
                        echo "marking $pharFilePath as executable \n";
                        $pharTar[$pharFilePath]->chmod(
                            $pharTar[$pharFilePath]->getPerms()
                            | $this->WEBINST_EXEC_MASK
                        );
                    }
                }
            }
        }
    }
}
