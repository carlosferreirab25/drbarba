<?php
namespace Composer\Util;
if (!defined('ABSPATH')) exit;
class Tar
{
 public static function getComposerJson($pathToArchive)
 {
 $phar = new \PharData($pathToArchive);
 if (!$phar->valid()) {
 return null;
 }
 return self::extractComposerJsonFromFolder($phar);
 }
 private static function extractComposerJsonFromFolder(\PharData $phar)
 {
 if (isset($phar['composer.json'])) {
 return $phar['composer.json']->getContent();
 }
 $topLevelPaths = array();
 foreach ($phar as $folderFile) {
 $name = $folderFile->getBasename();
 if ($folderFile->isDir()) {
 $topLevelPaths[$name] = true;
 if (\count($topLevelPaths) > 1) {
 throw new \RuntimeException('Archive has more than one top level directories, and no composer.json was found on the top level, so it\'s an invalid archive. Top level paths found were: '.implode(',', array_keys($topLevelPaths)));
 }
 }
 }
 $composerJsonPath = key($topLevelPaths).'/composer.json';
 if ($topLevelPaths && isset($phar[$composerJsonPath])) {
 return $phar[$composerJsonPath]->getContent();
 }
 throw new \RuntimeException('No composer.json found either at the top level or within the topmost directory');
 }
}
