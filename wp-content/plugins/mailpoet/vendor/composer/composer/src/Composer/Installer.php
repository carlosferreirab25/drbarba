<?php
namespace Composer;
if (!defined('ABSPATH')) exit;
use Composer\Autoload\AutoloadGenerator;
use Composer\Console\GithubActionError;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\LocalRepoTransaction;
use Composer\DependencyResolver\LockTransaction;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\PoolOptimizer;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Solver;
use Composer\DependencyResolver\SolverProblemsException;
use Composer\DependencyResolver\PolicyInterface;
use Composer\Downloader\DownloadManager;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Filter\PlatformRequirementFilter\IgnoreListPlatformRequirementFilter;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterInterface;
use Composer\Installer\InstallationManager;
use Composer\Installer\InstallerEvents;
use Composer\Installer\SuggestedPackagesReporter;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\RootAliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Link;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Version\VersionParser;
use Composer\Package\Package;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositorySet;
use Composer\Repository\CompositeRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Package\Locker;
use Composer\Package\RootPackageInterface;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\InstalledRepository;
use Composer\Repository\RootPackageRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Repository\LockArrayRepository;
use Composer\Script\ScriptEvents;
use Composer\Util\Platform;
class Installer
{
 const ERROR_NONE = 0; // no error/success state
 const ERROR_GENERIC_FAILURE = 1;
 const ERROR_NO_LOCK_FILE_FOR_PARTIAL_UPDATE = 3;
 const ERROR_LOCK_FILE_INVALID = 4;
 // used/declared in SolverProblemsException, carried over here for completeness
 const ERROR_DEPENDENCY_RESOLUTION_FAILED = 2;
 protected $io;
 protected $config;
 protected $package;
 // TODO can we get rid of the below and just use the package itself?
 protected $fixedRootPackage;
 protected $downloadManager;
 protected $repositoryManager;
 protected $locker;
 protected $installationManager;
 protected $eventDispatcher;
 protected $autoloadGenerator;
 protected $preferSource = false;
 protected $preferDist = false;
 protected $optimizeAutoloader = false;
 protected $classMapAuthoritative = false;
 protected $apcuAutoloader = false;
 protected $apcuAutoloaderPrefix = null;
 protected $devMode = false;
 protected $dryRun = false;
 protected $verbose = false;
 protected $update = false;
 protected $install = true;
 protected $dumpAutoloader = true;
 protected $runScripts = true;
 protected $preferStable = false;
 protected $preferLowest = false;
 protected $writeLock;
 protected $executeOperations = true;
 protected $updateMirrors = false;
 protected $updateAllowList = null;
 protected $updateAllowTransitiveDependencies = Request::UPDATE_ONLY_LISTED;
 protected $suggestedPackagesReporter;
 protected $platformRequirementFilter;
 protected $additionalFixedRepository;
 public function __construct(IOInterface $io, Config $config, RootPackageInterface $package, DownloadManager $downloadManager, RepositoryManager $repositoryManager, Locker $locker, InstallationManager $installationManager, EventDispatcher $eventDispatcher, AutoloadGenerator $autoloadGenerator)
 {
 $this->io = $io;
 $this->config = $config;
 $this->package = $package;
 $this->downloadManager = $downloadManager;
 $this->repositoryManager = $repositoryManager;
 $this->locker = $locker;
 $this->installationManager = $installationManager;
 $this->eventDispatcher = $eventDispatcher;
 $this->autoloadGenerator = $autoloadGenerator;
 $this->suggestedPackagesReporter = new SuggestedPackagesReporter($this->io);
 $this->platformRequirementFilter = PlatformRequirementFilterFactory::ignoreNothing();
 $this->writeLock = $config->get('lock');
 }
 public function run()
 {
 // Disable GC to save CPU cycles, as the dependency solver can create hundreds of thousands
 // of PHP objects, the GC can spend quite some time walking the tree of references looking
 // for stuff to collect while there is nothing to collect. This slows things down dramatically
 // and turning it off results in much better performance. Do not try this at home however.
 gc_collect_cycles();
 gc_disable();
 if ($this->updateAllowList && $this->updateMirrors) {
 throw new \RuntimeException("The installer options updateMirrors and updateAllowList are mutually exclusive.");
 }
 $isFreshInstall = $this->repositoryManager->getLocalRepository()->isFresh();
 // Force update if there is no lock file present
 if (!$this->update && !$this->locker->isLocked()) {
 $this->io->writeError('<warning>No composer.lock file present. Updating dependencies to latest instead of installing from lock file. See https://getcomposer.org/install for more information.</warning>');
 $this->update = true;
 }
 if ($this->dryRun) {
 $this->verbose = true;
 $this->runScripts = false;
 $this->executeOperations = false;
 $this->writeLock = false;
 $this->dumpAutoloader = false;
 $this->mockLocalRepositories($this->repositoryManager);
 }
 if ($this->update && !$this->install) {
 $this->dumpAutoloader = false;
 }
 if ($this->runScripts) {
 Platform::putEnv('COMPOSER_DEV_MODE', $this->devMode ? '1' : '0');
 // dispatch pre event
 // should we treat this more strictly as running an update and then running an install, triggering events multiple times?
 $eventName = $this->update ? ScriptEvents::PRE_UPDATE_CMD : ScriptEvents::PRE_INSTALL_CMD;
 $this->eventDispatcher->dispatchScript($eventName, $this->devMode);
 }
 $this->downloadManager->setPreferSource($this->preferSource);
 $this->downloadManager->setPreferDist($this->preferDist);
 $localRepo = $this->repositoryManager->getLocalRepository();
 try {
 if ($this->update) {
 $res = $this->doUpdate($localRepo, $this->install);
 } else {
 $res = $this->doInstall($localRepo);
 }
 if ($res !== 0) {
 return $res;
 }
 } catch (\Exception $e) {
 if ($this->executeOperations && $this->install && $this->config->get('notify-on-install')) {
 $this->installationManager->notifyInstalls($this->io);
 }
 throw $e;
 }
 if ($this->executeOperations && $this->install && $this->config->get('notify-on-install')) {
 $this->installationManager->notifyInstalls($this->io);
 }
 if ($this->update) {
 $installedRepo = new InstalledRepository(array(
 $this->locker->getLockedRepository($this->devMode),
 $this->createPlatformRepo(false),
 new RootPackageRepository(clone $this->package),
 ));
 if ($isFreshInstall) {
 $this->suggestedPackagesReporter->addSuggestionsFromPackage($this->package);
 }
 $this->suggestedPackagesReporter->outputMinimalistic($installedRepo);
 }
 // Find abandoned packages and warn user
 $lockedRepository = $this->locker->getLockedRepository(true);
 foreach ($lockedRepository->getPackages() as $package) {
 if (!$package instanceof CompletePackage || !$package->isAbandoned()) {
 continue;
 }
 $replacement = is_string($package->getReplacementPackage())
 ? 'Use ' . $package->getReplacementPackage() . ' instead'
 : 'No replacement was suggested';
 $this->io->writeError(
 sprintf(
 "<warning>Package %s is abandoned, you should avoid using it. %s.</warning>",
 $package->getPrettyName(),
 $replacement
 )
 );
 }
 if ($this->dumpAutoloader) {
 // write autoloader
 if ($this->optimizeAutoloader) {
 $this->io->writeError('<info>Generating optimized autoload files</info>');
 } else {
 $this->io->writeError('<info>Generating autoload files</info>');
 }
 $this->autoloadGenerator->setClassMapAuthoritative($this->classMapAuthoritative);
 $this->autoloadGenerator->setApcu($this->apcuAutoloader, $this->apcuAutoloaderPrefix);
 $this->autoloadGenerator->setRunScripts($this->runScripts);
 $this->autoloadGenerator->setPlatformRequirementFilter($this->platformRequirementFilter);
 $this->autoloadGenerator->dump($this->config, $localRepo, $this->package, $this->installationManager, 'composer', $this->optimizeAutoloader);
 }
 if ($this->install && $this->executeOperations) {
 // force binaries re-generation in case they are missing
 foreach ($localRepo->getPackages() as $package) {
 $this->installationManager->ensureBinariesPresence($package);
 }
 }
 $fundingCount = 0;
 foreach ($localRepo->getPackages() as $package) {
 if ($package instanceof CompletePackageInterface && !$package instanceof AliasPackage && $package->getFunding()) {
 $fundingCount++;
 }
 }
 if ($fundingCount > 0) {
 $this->io->writeError(array(
 sprintf(
 "<info>%d package%s you are using %s looking for funding.</info>",
 $fundingCount,
 1 === $fundingCount ? '' : 's',
 1 === $fundingCount ? 'is' : 'are'
 ),
 '<info>Use the `composer fund` command to find out more!</info>',
 ));
 }
 if ($this->runScripts) {
 // dispatch post event
 $eventName = $this->update ? ScriptEvents::POST_UPDATE_CMD : ScriptEvents::POST_INSTALL_CMD;
 $this->eventDispatcher->dispatchScript($eventName, $this->devMode);
 }
 // re-enable GC except on HHVM which triggers a warning here
 if (!defined('HHVM_VERSION')) {
 gc_enable();
 }
 return 0;
 }
 protected function doUpdate(InstalledRepositoryInterface $localRepo, $doInstall)
 {
 $platformRepo = $this->createPlatformRepo(true);
 $aliases = $this->getRootAliases(true);
 $lockedRepository = null;
 try {
 if ($this->locker->isLocked()) {
 $lockedRepository = $this->locker->getLockedRepository(true);
 }
 } catch (\Seld\JsonLint\ParsingException $e) {
 if ($this->updateAllowList || $this->updateMirrors) {
 // in case we are doing a partial update or updating mirrors, the lock file is needed so we error
 throw $e;
 }
 // otherwise, ignoring parse errors as the lock file will be regenerated from scratch when
 // doing a full update
 }
 if (($this->updateAllowList || $this->updateMirrors) && !$lockedRepository) {
 $this->io->writeError('<error>Cannot update ' . ($this->updateMirrors ? 'lock file information' : 'only a partial set of packages') . ' without a lock file present. Run `composer update` to generate a lock file.</error>', true, IOInterface::QUIET);
 return self::ERROR_NO_LOCK_FILE_FOR_PARTIAL_UPDATE;
 }
 $this->io->writeError('<info>Loading composer repositories with package information</info>');
 // creating repository set
 $policy = $this->createPolicy(true);
 $repositorySet = $this->createRepositorySet(true, $platformRepo, $aliases);
 $repositories = $this->repositoryManager->getRepositories();
 foreach ($repositories as $repository) {
 $repositorySet->addRepository($repository);
 }
 if ($lockedRepository) {
 $repositorySet->addRepository($lockedRepository);
 }
 $request = $this->createRequest($this->fixedRootPackage, $platformRepo, $lockedRepository);
 $this->requirePackagesForUpdate($request, $lockedRepository, true);
 // pass the allow list into the request, so the pool builder can apply it
 if ($this->updateAllowList) {
 $request->setUpdateAllowList($this->updateAllowList, $this->updateAllowTransitiveDependencies);
 }
 $pool = $repositorySet->createPool($request, $this->io, $this->eventDispatcher, $this->createPoolOptimizer($policy));
 $this->io->writeError('<info>Updating dependencies</info>');
 // solve dependencies
 $solver = new Solver($policy, $pool, $this->io);
 try {
 $lockTransaction = $solver->solve($request, $this->platformRequirementFilter);
 $ruleSetSize = $solver->getRuleSetSize();
 $solver = null;
 } catch (SolverProblemsException $e) {
 $err = 'Your requirements could not be resolved to an installable set of packages.';
 $prettyProblem = $e->getPrettyString($repositorySet, $request, $pool, $this->io->isVerbose());
 $this->io->writeError('<error>'. $err .'</error>', true, IOInterface::QUIET);
 $this->io->writeError($prettyProblem);
 if (!$this->devMode) {
 $this->io->writeError('<warning>Running update with --no-dev does not mean require-dev is ignored, it just means the packages will not be installed. If dev requirements are blocking the update you have to resolve those problems.</warning>', true, IOInterface::QUIET);
 }
 $ghe = new GithubActionError($this->io);
 $ghe->emit($err."\n".$prettyProblem);
 return max(self::ERROR_GENERIC_FAILURE, $e->getCode());
 }
 $this->io->writeError("Analyzed ".count($pool)." packages to resolve dependencies", true, IOInterface::VERBOSE);
 $this->io->writeError("Analyzed ".$ruleSetSize." rules to resolve dependencies", true, IOInterface::VERBOSE);
 $pool = null;
 if (!$lockTransaction->getOperations()) {
 $this->io->writeError('Nothing to modify in lock file');
 }
 $exitCode = $this->extractDevPackages($lockTransaction, $platformRepo, $aliases, $policy, $lockedRepository);
 if ($exitCode !== 0) {
 return $exitCode;
 }
 // exists as of composer/semver 3.3.0
 if (method_exists('Composer\Semver\CompilingMatcher', 'clear')) { // @phpstan-ignore-line
 \Composer\Semver\CompilingMatcher::clear();
 }
 // write lock
 $platformReqs = $this->extractPlatformRequirements($this->package->getRequires());
 $platformDevReqs = $this->extractPlatformRequirements($this->package->getDevRequires());
 $installsUpdates = $uninstalls = array();
 if ($lockTransaction->getOperations()) {
 $installNames = $updateNames = $uninstallNames = array();
 foreach ($lockTransaction->getOperations() as $operation) {
 if ($operation instanceof InstallOperation) {
 $installsUpdates[] = $operation;
 $installNames[] = $operation->getPackage()->getPrettyName().':'.$operation->getPackage()->getFullPrettyVersion();
 } elseif ($operation instanceof UpdateOperation) {
 // when mirrors/metadata from a package gets updated we do not want to list it as an
 // update in the output as it is only an internal lock file metadata update
 if ($this->updateMirrors
 && $operation->getInitialPackage()->getName() == $operation->getTargetPackage()->getName()
 && $operation->getInitialPackage()->getVersion() == $operation->getTargetPackage()->getVersion()
 ) {
 continue;
 }
 $installsUpdates[] = $operation;
 $updateNames[] = $operation->getTargetPackage()->getPrettyName().':'.$operation->getTargetPackage()->getFullPrettyVersion();
 } elseif ($operation instanceof UninstallOperation) {
 $uninstalls[] = $operation;
 $uninstallNames[] = $operation->getPackage()->getPrettyName();
 }
 }
 if ($this->config->get('lock')) {
 $this->io->writeError(sprintf(
 "<info>Lock file operations: %d install%s, %d update%s, %d removal%s</info>",
 count($installNames),
 1 === count($installNames) ? '' : 's',
 count($updateNames),
 1 === count($updateNames) ? '' : 's',
 count($uninstalls),
 1 === count($uninstalls) ? '' : 's'
 ));
 if ($installNames) {
 $this->io->writeError("Installs: ".implode(', ', $installNames), true, IOInterface::VERBOSE);
 }
 if ($updateNames) {
 $this->io->writeError("Updates: ".implode(', ', $updateNames), true, IOInterface::VERBOSE);
 }
 if ($uninstalls) {
 $this->io->writeError("Removals: ".implode(', ', $uninstallNames), true, IOInterface::VERBOSE);
 }
 }
 }
 $sortByName = function ($a, $b) {
 if ($a instanceof UpdateOperation) {
 $a = $a->getTargetPackage()->getName();
 } else {
 $a = $a->getPackage()->getName();
 }
 if ($b instanceof UpdateOperation) {
 $b = $b->getTargetPackage()->getName();
 } else {
 $b = $b->getPackage()->getName();
 }
 return strcmp($a, $b);
 };
 usort($uninstalls, $sortByName);
 usort($installsUpdates, $sortByName);
 foreach (array_merge($uninstalls, $installsUpdates) as $operation) {
 // collect suggestions
 if ($operation instanceof InstallOperation) {
 $this->suggestedPackagesReporter->addSuggestionsFromPackage($operation->getPackage());
 }
 // output op if lock file is enabled, but alias op only in debug verbosity
 if ($this->config->get('lock') && (false === strpos($operation->getOperationType(), 'Alias') || $this->io->isDebug())) {
 $this->io->writeError(' - ' . $operation->show(true));
 }
 }
 $updatedLock = $this->locker->setLockData(
 $lockTransaction->getNewLockPackages(false, $this->updateMirrors),
 $lockTransaction->getNewLockPackages(true, $this->updateMirrors),
 $platformReqs,
 $platformDevReqs,
 $lockTransaction->getAliases($aliases),
 $this->package->getMinimumStability(),
 $this->package->getStabilityFlags(),
 $this->preferStable || $this->package->getPreferStable(),
 $this->preferLowest,
 $this->config->get('platform') ?: array(),
 $this->writeLock && $this->executeOperations
 );
 if ($updatedLock && $this->writeLock && $this->executeOperations) {
 $this->io->writeError('<info>Writing lock file</info>');
 }
 // see https://github.com/composer/composer/issues/2764
 if ($this->executeOperations && count($lockTransaction->getOperations()) > 0) {
 $vendorDir = $this->config->get('vendor-dir');
 if (is_dir($vendorDir)) {
 // suppress errors as this fails sometimes on OSX for no apparent reason
 // see https://github.com/composer/composer/issues/4070#issuecomment-129792748
 @touch($vendorDir);
 }
 }
 if ($doInstall) {
 // TODO ensure lock is used from locker as-is, since it may not have been written to disk in case of executeOperations == false
 return $this->doInstall($localRepo, true);
 }
 return 0;
 }
 protected function extractDevPackages(LockTransaction $lockTransaction, PlatformRepository $platformRepo, array $aliases, PolicyInterface $policy, LockArrayRepository $lockedRepository = null)
 {
 if (!$this->package->getDevRequires()) {
 return 0;
 }
 $resultRepo = new ArrayRepository(array());
 $loader = new ArrayLoader(null, true);
 $dumper = new ArrayDumper();
 foreach ($lockTransaction->getNewLockPackages(false) as $pkg) {
 $resultRepo->addPackage($loader->load($dumper->dump($pkg)));
 }
 $repositorySet = $this->createRepositorySet(true, $platformRepo, $aliases);
 $repositorySet->addRepository($resultRepo);
 $request = $this->createRequest($this->fixedRootPackage, $platformRepo);
 $this->requirePackagesForUpdate($request, $lockedRepository, false);
 $pool = $repositorySet->createPoolWithAllPackages();
 $solver = new Solver($policy, $pool, $this->io);
 try {
 $nonDevLockTransaction = $solver->solve($request, $this->platformRequirementFilter);
 $solver = null;
 } catch (SolverProblemsException $e) {
 $err = 'Unable to find a compatible set of packages based on your non-dev requirements alone.';
 $prettyProblem = $e->getPrettyString($repositorySet, $request, $pool, $this->io->isVerbose(), true);
 $this->io->writeError('<error>'. $err .'</error>', true, IOInterface::QUIET);
 $this->io->writeError('Your requirements can be resolved successfully when require-dev packages are present.');
 $this->io->writeError('You may need to move packages from require-dev or some of their dependencies to require.');
 $this->io->writeError($prettyProblem);
 $ghe = new GithubActionError($this->io);
 $ghe->emit($err."\n".$prettyProblem);
 return $e->getCode();
 }
 $lockTransaction->setNonDevPackages($nonDevLockTransaction);
 return 0;
 }
 protected function doInstall(InstalledRepositoryInterface $localRepo, $alreadySolved = false)
 {
 if ($this->config->get('lock')) {
 $this->io->writeError('<info>Installing dependencies from lock file'.($this->devMode ? ' (including require-dev)' : '').'</info>');
 }
 $lockedRepository = $this->locker->getLockedRepository($this->devMode);
 // verify that the lock file works with the current platform repository
 // we can skip this part if we're doing this as the second step after an update
 if (!$alreadySolved) {
 $this->io->writeError('<info>Verifying lock file contents can be installed on current platform.</info>');
 $platformRepo = $this->createPlatformRepo(false);
 // creating repository set
 $policy = $this->createPolicy(false);
 // use aliases from lock file only, so empty root aliases here
 $repositorySet = $this->createRepositorySet(false, $platformRepo, array(), $lockedRepository);
 $repositorySet->addRepository($lockedRepository);
 // creating requirements request
 $request = $this->createRequest($this->fixedRootPackage, $platformRepo, $lockedRepository);
 if (!$this->locker->isFresh()) {
 $this->io->writeError('<warning>Warning: The lock file is not up to date with the latest changes in composer.json. You may be getting outdated dependencies. It is recommended that you run `composer update` or `composer update <package name>`.</warning>', true, IOInterface::QUIET);
 }
 foreach ($lockedRepository->getPackages() as $package) {
 $request->fixLockedPackage($package);
 }
 foreach ($this->locker->getPlatformRequirements($this->devMode) as $link) {
 $request->requireName($link->getTarget(), $link->getConstraint());
 }
 $pool = $repositorySet->createPool($request, $this->io, $this->eventDispatcher);
 // solve dependencies
 $solver = new Solver($policy, $pool, $this->io);
 try {
 $lockTransaction = $solver->solve($request, $this->platformRequirementFilter);
 $solver = null;
 // installing the locked packages on this platform resulted in lock modifying operations, there wasn't a conflict, but the lock file as-is seems to not work on this system
 if (0 !== count($lockTransaction->getOperations())) {
 $this->io->writeError('<error>Your lock file cannot be installed on this system without changes. Please run composer update.</error>', true, IOInterface::QUIET);
 return self::ERROR_LOCK_FILE_INVALID;
 }
 } catch (SolverProblemsException $e) {
 $err = 'Your lock file does not contain a compatible set of packages. Please run composer update.';
 $prettyProblem = $e->getPrettyString($repositorySet, $request, $pool, $this->io->isVerbose());
 $this->io->writeError('<error>'. $err .'</error>', true, IOInterface::QUIET);
 $this->io->writeError($prettyProblem);
 $ghe = new GithubActionError($this->io);
 $ghe->emit($err."\n".$prettyProblem);
 return max(self::ERROR_GENERIC_FAILURE, $e->getCode());
 }
 }
 // TODO in how far do we need to do anything here to ensure dev packages being updated to latest in lock without version change are treated correctly?
 $localRepoTransaction = new LocalRepoTransaction($lockedRepository, $localRepo);
 $this->eventDispatcher->dispatchInstallerEvent(InstallerEvents::PRE_OPERATIONS_EXEC, $this->devMode, $this->executeOperations, $localRepoTransaction);
 if (!$localRepoTransaction->getOperations()) {
 $this->io->writeError('Nothing to install, update or remove');
 }
 if ($localRepoTransaction->getOperations()) {
 $installs = $updates = $uninstalls = array();
 foreach ($localRepoTransaction->getOperations() as $operation) {
 if ($operation instanceof InstallOperation) {
 $installs[] = $operation->getPackage()->getPrettyName().':'.$operation->getPackage()->getFullPrettyVersion();
 } elseif ($operation instanceof UpdateOperation) {
 $updates[] = $operation->getTargetPackage()->getPrettyName().':'.$operation->getTargetPackage()->getFullPrettyVersion();
 } elseif ($operation instanceof UninstallOperation) {
 $uninstalls[] = $operation->getPackage()->getPrettyName();
 }
 }
 $this->io->writeError(sprintf(
 "<info>Package operations: %d install%s, %d update%s, %d removal%s</info>",
 count($installs),
 1 === count($installs) ? '' : 's',
 count($updates),
 1 === count($updates) ? '' : 's',
 count($uninstalls),
 1 === count($uninstalls) ? '' : 's'
 ));
 if ($installs) {
 $this->io->writeError("Installs: ".implode(', ', $installs), true, IOInterface::VERBOSE);
 }
 if ($updates) {
 $this->io->writeError("Updates: ".implode(', ', $updates), true, IOInterface::VERBOSE);
 }
 if ($uninstalls) {
 $this->io->writeError("Removals: ".implode(', ', $uninstalls), true, IOInterface::VERBOSE);
 }
 }
 if ($this->executeOperations) {
 $localRepo->setDevPackageNames($this->locker->getDevPackageNames());
 $this->installationManager->execute($localRepo, $localRepoTransaction->getOperations(), $this->devMode, $this->runScripts);
 } else {
 foreach ($localRepoTransaction->getOperations() as $operation) {
 // output op, but alias op only in debug verbosity
 if (false === strpos($operation->getOperationType(), 'Alias') || $this->io->isDebug()) {
 $this->io->writeError(' - ' . $operation->show(false));
 }
 }
 }
 return 0;
 }
 protected function createPlatformRepo($forUpdate)
 {
 if ($forUpdate) {
 $platformOverrides = $this->config->get('platform') ?: array();
 } else {
 $platformOverrides = $this->locker->getPlatformOverrides();
 }
 return new PlatformRepository(array(), $platformOverrides);
 }
 private function createRepositorySet($forUpdate, PlatformRepository $platformRepo, array $rootAliases = array(), $lockedRepository = null)
 {
 if ($forUpdate) {
 $minimumStability = $this->package->getMinimumStability();
 $stabilityFlags = $this->package->getStabilityFlags();
 $requires = array_merge($this->package->getRequires(), $this->package->getDevRequires());
 } else {
 $minimumStability = $this->locker->getMinimumStability();
 $stabilityFlags = $this->locker->getStabilityFlags();
 $requires = array();
 foreach ($lockedRepository->getPackages() as $package) {
 $constraint = new Constraint('=', $package->getVersion());
 $constraint->setPrettyString($package->getPrettyVersion());
 $requires[$package->getName()] = $constraint;
 }
 }
 $rootRequires = array();
 foreach ($requires as $req => $constraint) {
 if ($constraint instanceof Link) {
 $constraint = $constraint->getConstraint();
 }
 // skip platform requirements from the root package to avoid filtering out existing platform packages
 if ($this->platformRequirementFilter->isIgnored($req)) {
 continue;
 } elseif ($this->platformRequirementFilter instanceof IgnoreListPlatformRequirementFilter) {
 $constraint = $this->platformRequirementFilter->filterConstraint($req, $constraint);
 }
 $rootRequires[$req] = $constraint;
 }
 $this->fixedRootPackage = clone $this->package;
 $this->fixedRootPackage->setRequires(array());
 $this->fixedRootPackage->setDevRequires(array());
 $stabilityFlags[$this->package->getName()] = BasePackage::$stabilities[VersionParser::parseStability($this->package->getVersion())];
 $repositorySet = new RepositorySet($minimumStability, $stabilityFlags, $rootAliases, $this->package->getReferences(), $rootRequires);
 $repositorySet->addRepository(new RootPackageRepository($this->fixedRootPackage));
 $repositorySet->addRepository($platformRepo);
 if ($this->additionalFixedRepository) {
 // allow using installed repos if needed to avoid warnings about installed repositories being used in the RepositorySet
 // see https://github.com/composer/composer/pull/9574
 $additionalFixedRepositories = $this->additionalFixedRepository;
 if ($additionalFixedRepositories instanceof CompositeRepository) {
 $additionalFixedRepositories = $additionalFixedRepositories->getRepositories();
 } else {
 $additionalFixedRepositories = array($additionalFixedRepositories);
 }
 foreach ($additionalFixedRepositories as $additionalFixedRepository) {
 if ($additionalFixedRepository instanceof InstalledRepository || $additionalFixedRepository instanceof InstalledRepositoryInterface) {
 $repositorySet->allowInstalledRepositories();
 break;
 }
 }
 $repositorySet->addRepository($this->additionalFixedRepository);
 }
 return $repositorySet;
 }
 private function createPolicy($forUpdate)
 {
 $preferStable = null;
 $preferLowest = null;
 if (!$forUpdate) {
 $preferStable = $this->locker->getPreferStable();
 $preferLowest = $this->locker->getPreferLowest();
 }
 // old lock file without prefer stable/lowest will return null
 // so in this case we use the composer.json info
 if (null === $preferStable) {
 $preferStable = $this->preferStable || $this->package->getPreferStable();
 }
 if (null === $preferLowest) {
 $preferLowest = $this->preferLowest;
 }
 return new DefaultPolicy($preferStable, $preferLowest);
 }
 private function createRequest(RootPackageInterface $rootPackage, PlatformRepository $platformRepo, LockArrayRepository $lockedRepository = null)
 {
 $request = new Request($lockedRepository);
 $request->fixPackage($rootPackage);
 if ($rootPackage instanceof RootAliasPackage) {
 $request->fixPackage($rootPackage->getAliasOf());
 }
 $fixedPackages = $platformRepo->getPackages();
 if ($this->additionalFixedRepository) {
 $fixedPackages = array_merge($fixedPackages, $this->additionalFixedRepository->getPackages());
 }
 // fix the version of all platform packages + additionally installed packages
 // to prevent the solver trying to remove or update those
 // TODO why not replaces?
 $provided = $rootPackage->getProvides();
 foreach ($fixedPackages as $package) {
 // skip platform packages that are provided by the root package
 if ($package->getRepository() !== $platformRepo
 || !isset($provided[$package->getName()])
 || !$provided[$package->getName()]->getConstraint()->matches(new Constraint('=', $package->getVersion()))
 ) {
 $request->fixPackage($package);
 }
 }
 return $request;
 }
 private function requirePackagesForUpdate(Request $request, LockArrayRepository $lockedRepository = null, $includeDevRequires = true)
 {
 // if we're updating mirrors we want to keep exactly the same versions installed which are in the lock file, but we want current remote metadata
 if ($this->updateMirrors) {
 $excludedPackages = array();
 if (!$includeDevRequires) {
 $excludedPackages = array_flip($this->locker->getDevPackageNames());
 }
 foreach ($lockedRepository->getPackages() as $lockedPackage) {
 // exclude alias packages here as for root aliases, both alias and aliased are
 // present in the lock repo and we only want to require the aliased version
 if (!$lockedPackage instanceof AliasPackage && !isset($excludedPackages[$lockedPackage->getName()])) {
 $request->requireName($lockedPackage->getName(), new Constraint('==', $lockedPackage->getVersion()));
 }
 }
 } else {
 $links = $this->package->getRequires();
 if ($includeDevRequires) {
 $links = array_merge($links, $this->package->getDevRequires());
 }
 foreach ($links as $link) {
 $request->requireName($link->getTarget(), $link->getConstraint());
 }
 }
 }
 private function getRootAliases($forUpdate)
 {
 if ($forUpdate) {
 $aliases = $this->package->getAliases();
 } else {
 $aliases = $this->locker->getAliases();
 }
 return $aliases;
 }
 private function extractPlatformRequirements(array $links)
 {
 $platformReqs = array();
 foreach ($links as $link) {
 if (PlatformRepository::isPlatformPackage($link->getTarget())) {
 $platformReqs[$link->getTarget()] = $link->getPrettyConstraint();
 }
 }
 return $platformReqs;
 }
 private function mockLocalRepositories(RepositoryManager $rm)
 {
 $packages = array();
 foreach ($rm->getLocalRepository()->getPackages() as $package) {
 $packages[(string) $package] = clone $package;
 }
 foreach ($packages as $key => $package) {
 if ($package instanceof AliasPackage) {
 $alias = (string) $package->getAliasOf();
 $className = get_class($package);
 $packages[$key] = new $className($packages[$alias], $package->getVersion(), $package->getPrettyVersion());
 }
 }
 $rm->setLocalRepository(
 new InstalledArrayRepository($packages)
 );
 }
 private function createPoolOptimizer(PolicyInterface $policy)
 {
 // Not the best architectural decision here, would need to be able
 // to configure from the outside of Installer but this is only
 // a debugging tool and should never be required in any other use case
 if ('0' === Platform::getEnv('COMPOSER_POOL_OPTIMIZER')) {
 $this->io->write('Pool Optimizer was disabled for debugging purposes.', true, IOInterface::DEBUG);
 return null;
 }
 return new PoolOptimizer($policy);
 }
 public static function create(IOInterface $io, Composer $composer)
 {
 return new static(
 $io,
 $composer->getConfig(),
 $composer->getPackage(),
 $composer->getDownloadManager(),
 $composer->getRepositoryManager(),
 $composer->getLocker(),
 $composer->getInstallationManager(),
 $composer->getEventDispatcher(),
 $composer->getAutoloadGenerator()
 );
 }
 public function setAdditionalFixedRepository(RepositoryInterface $additionalFixedRepository)
 {
 $this->additionalFixedRepository = $additionalFixedRepository;
 return $this;
 }
 public function setDryRun($dryRun = true)
 {
 $this->dryRun = (bool) $dryRun;
 return $this;
 }
 public function isDryRun()
 {
 return $this->dryRun;
 }
 public function setPreferSource($preferSource = true)
 {
 $this->preferSource = (bool) $preferSource;
 return $this;
 }
 public function setPreferDist($preferDist = true)
 {
 $this->preferDist = (bool) $preferDist;
 return $this;
 }
 public function setOptimizeAutoloader($optimizeAutoloader)
 {
 $this->optimizeAutoloader = (bool) $optimizeAutoloader;
 if (!$this->optimizeAutoloader) {
 // Force classMapAuthoritative off when not optimizing the
 // autoloader
 $this->setClassMapAuthoritative(false);
 }
 return $this;
 }
 public function setClassMapAuthoritative($classMapAuthoritative)
 {
 $this->classMapAuthoritative = (bool) $classMapAuthoritative;
 if ($this->classMapAuthoritative) {
 // Force optimizeAutoloader when classmap is authoritative
 $this->setOptimizeAutoloader(true);
 }
 return $this;
 }
 public function setApcuAutoloader($apcuAutoloader, $apcuAutoloaderPrefix = null)
 {
 $this->apcuAutoloader = $apcuAutoloader;
 $this->apcuAutoloaderPrefix = $apcuAutoloaderPrefix;
 return $this;
 }
 public function setUpdate($update)
 {
 $this->update = (bool) $update;
 return $this;
 }
 public function setInstall($install)
 {
 $this->install = (bool) $install;
 return $this;
 }
 public function setDevMode($devMode = true)
 {
 $this->devMode = (bool) $devMode;
 return $this;
 }
 public function setDumpAutoloader($dumpAutoloader = true)
 {
 $this->dumpAutoloader = (bool) $dumpAutoloader;
 return $this;
 }
 public function setRunScripts($runScripts = true)
 {
 $this->runScripts = (bool) $runScripts;
 return $this;
 }
 public function setConfig(Config $config)
 {
 $this->config = $config;
 return $this;
 }
 public function setVerbose($verbose = true)
 {
 $this->verbose = (bool) $verbose;
 return $this;
 }
 public function isVerbose()
 {
 return $this->verbose;
 }
 public function setIgnorePlatformRequirements($ignorePlatformReqs)
 {
 trigger_error('Installer::setIgnorePlatformRequirements is deprecated since Composer 2.2, use setPlatformRequirementFilter instead.', E_USER_DEPRECATED);
 return $this->setPlatformRequirementFilter(PlatformRequirementFilterFactory::fromBoolOrList($ignorePlatformReqs));
 }
 public function setPlatformRequirementFilter(PlatformRequirementFilterInterface $platformRequirementFilter)
 {
 $this->platformRequirementFilter = $platformRequirementFilter;
 return $this;
 }
 public function setUpdateMirrors($updateMirrors)
 {
 $this->updateMirrors = $updateMirrors;
 return $this;
 }
 public function setUpdateAllowList(array $packages)
 {
 $this->updateAllowList = array_flip(array_map('strtolower', $packages));
 return $this;
 }
 public function setUpdateAllowTransitiveDependencies($updateAllowTransitiveDependencies)
 {
 if (!in_array($updateAllowTransitiveDependencies, array(Request::UPDATE_ONLY_LISTED, Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS_NO_ROOT_REQUIRE, Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS), true)) {
 throw new \RuntimeException("Invalid value for updateAllowTransitiveDependencies supplied");
 }
 $this->updateAllowTransitiveDependencies = $updateAllowTransitiveDependencies;
 return $this;
 }
 public function setPreferStable($preferStable = true)
 {
 $this->preferStable = (bool) $preferStable;
 return $this;
 }
 public function setPreferLowest($preferLowest = true)
 {
 $this->preferLowest = (bool) $preferLowest;
 return $this;
 }
 public function setWriteLock($writeLock = true)
 {
 $this->writeLock = (bool) $writeLock;
 return $this;
 }
 public function setExecuteOperations($executeOperations = true)
 {
 $this->executeOperations = (bool) $executeOperations;
 return $this;
 }
 public function disablePlugins()
 {
 $this->installationManager->disablePlugins();
 return $this;
 }
 public function setSuggestedPackagesReporter(SuggestedPackagesReporter $suggestedPackagesReporter)
 {
 $this->suggestedPackagesReporter = $suggestedPackagesReporter;
 return $this;
 }
}
