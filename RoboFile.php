<?php
/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */

//require '/Users/Shared/www/notifier/vendor/autoload.php';

//use Joli\JoliNotif\Notification;
//use Joli\JoliNotif\NotifierFactory;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class RoboFile extends \Robo\Tasks
{
	const SERVER = "/Users/Shared/www/";
	const FORUM = "/Users/Shared/www/public_html/devboards/";
	const GIT = "/Users/Shared/www/git/";

	protected $dirsToScan = [];
	
	/**
	 *
	 */
	public function symlinkCreate()
	{
		$this->scanForConfigs();
		$this->createSymlinks();
//		$this->notify('Symlink Updater', 'Symlinks created successfully.');
	}
	
	/**
	 * @param array $opts
	 */
	public function symlinkClean($opts = ['erase' => false])
	{
		$this->cleanSymlinks($opts);
//		$this->notify('Symlink Updater', 'Symlinks ' . ($opts['erase'] ? 'erased' : 'cleaned') . ' successfully.');
	}
	
	/**
	 * @param string $onlyDir
	 */
	public function syntaxCheck($onlyDir = '')
	{
		$this->scanForConfigs($onlyDir, true);
		$this->checkSyntax();
	}
	
	/**
	 * @param $product
	 * @param $version
	 * @param array $opts
	 */
	public function release($product, $version, $opts = ['repoDir' => NULL, 'changeLogDir' => NULL])
	{
		$version = ($version[0] != 'v' ? 'v' : '') . $version;
		
		if ($opts['repoDir'])
		{
			$repoDir = $opts['repoDir'];
		}
		else
		{
			$repoDir = self::GIT . $product;
		}

		$this->output()->setVerbosity(OutputInterface::VERBOSITY_QUIET);
		$this->taskGitStack()
			->printOutput(false)
			->dir($repoDir)
			->exec(['tag', '--delete', $version])
			->exec(['push', 'origin', ':refs/tags/' . $version])
			->run()
		;
		$this->taskGitStack()
			->printOutput(false)
			->dir($repoDir)
			->exec(['branch', '--delete', 'release/' . $version])
			->exec(['push', 'origin', '--delete', 'release/' . $version])
			->run()
		;
		$this->output()->setVerbosity(OutputInterface::VERBOSITY_NORMAL);

		/** @var \Robo\Collection\Collection $collection */
		$collection = $this->collection();

		// Update changelog
		$this->_changeLog($product, $version, $collection, $opts['repoDir'], $opts['changeLogDir']);
		
		$this->taskGitStack()
			->printOutput(false)
			->dir($repoDir)
			->exec(['flow', 'release', 'start', $version])
			->exec(['flow', 'release', 'publish', $version])
			->exec(['flow', 'release', 'finish', $version, '-m', $version, '--push', '--keepremote'])
			->addToCollection($collection)
		;

		$collection->run();
	}

	/**
	 * @param $product
	 * @param $version
	 * @param array $opts
	 */
	public function changeLog($product, $version, $opts = ['repoDir' => NULL, 'changeLogDir' => NULL])
	{
		/** @var \Robo\Collection\Collection $collection */
		$collection = $this->collection();

		$this->_changeLog($product, $version, $collection, $opts['repoDir'], $opts['changeLogDir']);

		$collection->run();
	}

	/**
	 * @param $product
	 * @param $version
	 * @param \Robo\Collection\Collection $collection
	 * @param null $repoDir
	 * @param null $changeLogDir
	 */
	protected function _changeLog($product, $version, \Robo\Collection\Collection $collection, $repoDir = NULL, $changeLogDir = NULL)
	{
		$ds = DIRECTORY_SEPARATOR;

		$version = ($version[0] != 'v' ? 'v' : '') . $version;

		if (!$repoDir)
		{
			$repoDir = self::GIT . $product;
		}

		if (!$changeLogDir)
		{
			$changeLogDir = $repoDir . $ds . $product;
		}

		$changeLogTask = $this->taskChangelog($changeLogDir . $ds . 'CHANGELOG.md')
			->version($version)
		;

		while ($resp = $this->ask("Changed in this release: "))
		{
			$changeLogTask->change($resp);
		}

		if ($changeLogTask->getChanges())
		{
			$changeLogTask->addToCollection($collection);

			// Commit change log
			$this->taskGitStack()
				->stopOnFail()
				->printOutput(false)
				->dir($repoDir)
				->add('CHANGELOG.md')
				->commit('Updated changelog')
				->push()
				->addToCollection($collection)
			;
		}
	}
	
	/**
	 * @param string $onlyDir
	 * @param bool $skipFlag
	 */
	protected function scanForConfigs($onlyDir = '', $skipFlag = false)
	{
		if ($onlyDir)
		{
			if (file_exists(self::GIT . $onlyDir . '/config.inc.php'))
			{
				// Grab the configuration file
				require(self::GIT . $onlyDir . '/config.inc.php');
			}
		}
		else
		{
			$d = dir(self::GIT);
			while (false !== ($entry = $d->read()))
			{
				if (
					$entry[0] == '.'
					OR !is_dir(self::GIT . $entry)
					OR !file_exists(self::GIT . $entry . '/config.inc.php')
				)
				{
					// Skip this
					continue;
				}

				// Grab the configuration file
				require(self::GIT . $entry . '/config.inc.php');
			}
			$d->close();
		}
	}
	
	/**
	 * @param $pathToApplication
	 * @param $version
	 * @param array $replace
	 */
	protected function updateFramework($pathToApplication, $version, $replace = [])
	{
		$this->_mirrorDir(self::GIT . 'framework/' . $version, $pathToApplication);
		
		$d = new RecDir($pathToApplication . '/');
		while (false !== ($file = $d->read()))
		{
			$this->taskReplaceInFile($file)
				->from(array_keys($replace))
				->to($replace)
				->run();
		}
		$d->close();
	}
	
	/**
	 * @param $pathToApplication
	 * @param $version
	 * @param array $replace
	 */
	protected function updateFrameworkWithoutSymlink($pathToApplication, $version, $replace = [])
	{
		$this->_mirrorDir(self::FORUM . 'framework/' . $version, $pathToApplication);
		
		$d = new RecDir($pathToApplication . '/');
		while (false !== ($file = $d->read()))
		{
			$this->taskReplaceInFile($file)
				->from(array_keys($replace))
				->to($replace)
				->run();
		}
		$d->close();
	}
	
	/**
	 *
	 */
	protected function createSymlinks()
	{
		foreach ($this->dirsToScan as $folder => $dirs)
		{
			foreach ($dirs as $dir)
			{
				$d = new RecDir($dir);
				while (false !== ($entry = $d->read()))
				{
					// Grab our base entry
					$baseEntry = str_replace($dir, '', $entry);

					if (!is_dir(dirname(self::FORUM . $folder . '/' . $baseEntry)))
					{
						// Destination directory doesn't exist
						mkdir(dirname(self::FORUM . $folder . '/' . $baseEntry), 0755, true);
					}

					if (!is_link(self::FORUM . $folder . '/' . $baseEntry))
					{
						if (file_exists(self::FORUM . $folder . '/' . $baseEntry))
						{
							// Get rid of the "normal" file
							@unlink(self::FORUM . $folder . '/' . $baseEntry);
						}

						// Create the symlink
						$this->taskFileSystemStack()
							->symlink($entry, self::FORUM . $folder . '/' . $baseEntry)
						->run();
					}
				}
				$d->close();
			}
		}
	}
	
	/**
	 * @param $opts
	 */
	protected function cleanSymlinks($opts)
	{
		$d = new RecDir(self::FORUM);
		while (false !== ($entry = $d->read()))
		{
			if (is_link($entry) AND (!@readlink($entry) OR $opts['erase']))
			{
				// Remove the symlink
				@unlink($entry);

				// Log this
				$this->say('Deleted Symlink: ' . $entry);
			}
		}
		$d->close();
	}
	
	/**
	 *
	 */
	protected function checkSyntax()
	{
		$taskStack = $this->taskExecStack()->printed(false)->stopOnFail();
		foreach ($this->dirsToScan as $folder => $dirs)
		{
			foreach ($dirs as $dir)
			{
				$d = new RecDir($dir);
				while (false !== ($entry = $d->read()))
				{
					if (pathinfo($entry, PATHINFO_EXTENSION) == 'php')
					{
						$taskStack = $taskStack->exec('php -l "' . $entry . '"');
					}
				}
				$d->close();
			}
		}
		$taskStack->run();
	}
	
	/**
	 * @param $dir
	 * @param $folder
	 */
	protected function addDirToScan($dir, $folder)
	{
		if (!isset($this->dirsToScan[$folder]))
		{
			// Make sure this is set
			$this->dirsToScan[$folder] = array();
		}

		// Now store the dir
		$this->dirsToScan[$folder][] = $dir;
	}
	
	/**
	 * @param $title
	 * @param $body
	 */
	protected function notify($title, $body)
	{
		/** @var \Joli\JoliNotif\Notifier $notifier */
		$notifier = NotifierFactory::create();

		if ($notifier)
		{
			$notification =
				(new Notification())
				->setTitle($title)
				->setBody($body);
			;

			$notifier->send($notification);
		}
	}
}


class RecDir
{
	protected $currentPath;
	protected $slash;
	protected $rootPath;
	protected $recursiveTree;
	protected $excludedRootDirs;

	function __construct($rootPath, $win = false, $excludedRootDirs = array())
	{
		switch ($win)
		{
			case true:
				$this->slash = '\\';
				break;
			default:
				$this->slash = '/';
		}
		$this->rootPath = $rootPath;
		$this->currentPath = $rootPath;
		$this->excludedRootDirs = $excludedRootDirs;
		$this->recursiveTree = array(dir($this->rootPath));
		$this->rewind();
	}

	function __destruct()
	{
		$this->close();
	}

	public function close()
	{
		while (true === ($d = array_pop($this->recursiveTree)))
		{
			$d->close();
		}
	}

	public function closeChildren()
	{
		while (count($this->recursiveTree) > 1 AND false !== ($d = array_pop($this->recursiveTree)))
		{
			$d->close();
			return true;
		}
		return false;
	}

	public function getRootPath()
	{
		if (isset($this->rootPath))
		{
			return $this->rootPath;
		}
		return false;
	}

	public function getCurrentPath()
	{
		if (isset($this->currentPath))
		{
			return $this->currentPath;
		}
		return false;
	}

	public function read($debug = false)
	{
		if ($debug)
		{
			var_dump($this->recursiveTree);
		}
		while (count($this->recursiveTree) > 0)
		{
			$d = end($this->recursiveTree);
			if ((false !== ($entry = $d->read())))
			{
				if ($entry != '.' AND $entry != '..' AND $entry != '.DS_Store')
				{
					$path = $d->path . $entry;

					if (is_file($path))
					{
						return $path;
					}
					else if (is_dir($path . $this->slash))
					{
						$this->currentPath = $path . $this->slash;
						if ($child = @dir($path . $this->slash))
						{
							$this->recursiveTree[] = $child;
						}
					}
				}
			}
			else
			{
				array_pop($this->recursiveTree)->close();
			}
		}
		return false;
	}

	public function rewind()
	{
		$this->closeChildren();
		$this->rewindCurrent();
	}

	public function rewindCurrent()
	{
		return end($this->recursiveTree)->rewind();
	}
}