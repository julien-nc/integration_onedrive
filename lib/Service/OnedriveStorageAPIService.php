<?php
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Onedrive\Service;

use DateTime;
use Exception;
use OCA\Onedrive\AppInfo\Application;
use OCA\Onedrive\BackgroundJob\ImportOnedriveJob;
use OCA\Onedrive\Exceptions\MaxDownloadSizeReachedException;
use OCP\BackgroundJob\IJobList;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

use OCP\IConfig;
use OCP\PreConditionNotMetException;
use Psr\Log\LoggerInterface;
use Throwable;

class OnedriveStorageAPIService {
	/**
	 * @var string
	 */
	private $appName;
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var IRootFolder
	 */
	private $root;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IJobList
	 */
	private $jobList;
	/**
	 * @var OnedriveAPIService
	 */
	private $onedriveApiService;
	/**
	 * @var UserScopeService
	 */
	private $userScopeService;

	/**
	 * Service to make requests to Onedrive API
	 */
	public function __construct(string $appName,
		LoggerInterface $logger,
		IRootFolder $root,
		IConfig $config,
		IJobList $jobList,
		UserScopeService $userScopeService,
		OnedriveAPIService $onedriveApiService) {
		$this->appName = $appName;
		$this->logger = $logger;
		$this->root = $root;
		$this->config = $config;
		$this->jobList = $jobList;
		$this->onedriveApiService = $onedriveApiService;
		$this->userScopeService = $userScopeService;
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function getStorageSize(string $userId): array {
		// onedrive storage size
		//		$onedriveUserId = $this->config->getUserValue($userId, Application::APP_ID, 'user_id');
		$result = $this->onedriveApiService->request($userId, 'me/drive');
		if (isset($result['error']) || !isset($result['quota'], $result['quota']['used'])) {
			return $result;
		}
		//		$driveId = $result['id'] ?? '';
		return [
			'usageInStorage' => $result['quota']['used'],
		];
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function startImportOnedrive(string $userId): array {
		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'onedrive_output_dir', '/OneDrive import');
		$targetPath = $targetPath ?: '/OneDrive import';

		$alreadyImporting = $this->config->getUserValue($userId, Application::APP_ID, 'importing_onedrive', '0') === '1';
		if ($alreadyImporting) {
			return ['targetPath' => $targetPath];
		}

		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if ($folder->getType() !== FileInfo::TYPE_FOLDER) {
				return ['error' => 'Impossible to create Onedrive folder'];
			}
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'importing_onedrive', '1');
		$this->config->setUserValue($userId, Application::APP_ID, 'imported_size', '0');
		$this->config->setUserValue($userId, Application::APP_ID, 'last_onedrive_import_timestamp', '0');
		$this->config->deleteUserValue($userId, Application::APP_ID, 'import_tree');

		$this->jobList->add(ImportOnedriveJob::class, ['user_id' => $userId]);
		return ['targetPath' => $targetPath];
	}

	/**
	 * @param string $userId
	 * @return void
	 */
	public function importOnedriveJob(string $userId): void {
		$this->logger->info('Importing onedrive files for ' . $userId);

		// in case SSE is enabled
		$this->userScopeService->setUserScope($userId);
		$this->userScopeService->setFilesystemScope($userId);

		$importingOnedrive = $this->config->getUserValue($userId, Application::APP_ID, 'importing_onedrive', '0') === '1';
		if (!$importingOnedrive) {
			return;
		}
		$jobRunning = $this->config->getUserValue($userId, Application::APP_ID, 'onedrive_import_running', '0') === '1';
		$nowTs = (new DateTime())->getTimestamp();
		if ($jobRunning) {
			$lastJobStart = $this->config->getUserValue($userId, Application::APP_ID, 'onedrive_import_job_last_start');
			if ($lastJobStart !== '' && ($nowTs - intval($lastJobStart) < Application::IMPORT_JOB_TIMEOUT)) {
				// last job has started less than an hour ago => we consider it can still be running
				return;
			}
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'onedrive_import_running', '1');
		$this->config->setUserValue($userId, Application::APP_ID, 'onedrive_import_job_last_start', strval($nowTs));

		// import batch of files
		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'onedrive_output_dir', '/OneDrive import');
		$targetPath = $targetPath ?: '/OneDrive import';

		try {
			$targetNode = $this->root->getUserFolder($userId)->get($targetPath);
			if ($targetNode->isShared()) {
				$this->logger->error('Target path ' . $targetPath . 'is shared, resorting to user root folder');
				$targetPath = '/';
			}
		} catch (NotFoundException) {
			// noop, folder doesn't exist
		} catch (NotPermittedException) {
			$this->logger->error('Cannot determine if target path ' . $targetPath . 'is shared, resorting to root folder');
			$targetPath = '/';
		}

		// get previous progress
		$importTreeStr = $this->config->getUserValue($userId, Application::APP_ID, 'import_tree', '[]');
		/** @var array $importTree */
		$importTree = ($importTreeStr === '[]' || $importTreeStr === '') ? [] : json_decode($importTreeStr, true);
		// import by batch of 500 MB
		$alreadyImportedSize = (float)$this->config->getUserValue($userId, Application::APP_ID, 'imported_size', '0');
		$alreadyImportedNumber = (int)$this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
		try {
			$result = $this->importFiles($userId, $targetPath, 500000000, $alreadyImportedSize, $alreadyImportedNumber, $importTree);
		} catch (Exception|Throwable $e) {
			$result = [
				'error' => 'Unknow job failure. ' . $e->getMessage(),
			];
		}
		if (isset($result['error']) || (isset($result['finished']) && $result['finished'])) {
			$this->config->setUserValue($userId, Application::APP_ID, 'importing_onedrive', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'imported_size', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'last_onedrive_import_timestamp', '0');
			if (isset($result['finished']) && $result['finished']) {
				$this->config->deleteUserValue($userId, Application::APP_ID, 'import_tree');
				$this->onedriveApiService->sendNCNotification($userId, 'import_onedrive_finished', [
					'nbImported' => $result['totalSeenNumber'],
					'targetPath' => $targetPath,
				]);
			}
		} else {
			// save progress
			$this->config->setUserValue($userId, Application::APP_ID, 'import_tree', json_encode($importTree));
			$ts = (new DateTime())->getTimestamp();
			$this->config->setUserValue($userId, Application::APP_ID, 'last_onedrive_import_timestamp', (string)$ts);
			$this->jobList->add(ImportOnedriveJob::class, ['user_id' => $userId]);
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'onedrive_import_running', '0');
	}

	/**
	 * @param string $userId
	 * @param string $targetPath
	 * @param ?int $maxDownloadSize
	 * @param float $alreadyImportedSize
	 * @param int $alreadyImportedNumber
	 * @param array $importTree
	 * @return array
	 * @throws \OCP\Files\NotFoundException
	 * @throws \OCP\Files\NotPermittedException
	 * @throws \OC\User\NoUserException
	 */
	public function importFiles(string $userId, string $targetPath,
		?int $maxDownloadSize = null, float $alreadyImportedSize = 0, int $alreadyImportedNumber = 0,
		array &$importTree = []): array {
		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$topFolder = $userFolder->newFolder($targetPath);
		} else {
			$topFolder = $userFolder->get($targetPath);
			if (!$topFolder instanceof Folder) {
				return ['error' => 'Impossible to create ' . $targetPath . ' folder'];
			}
		}

		$info = $this->getStorageSize($userId);
		if (isset($info['error'])) {
			return $info;
		}
		//		$onedriveStorageSize = $info['usageInStorage'];

		// iterate on unfinished directory list retrieved with getUserValue
		try {
			if (count($importTree) === 0) {
				$downloadResult = $this->downloadDir(
					$userId, $topFolder, $maxDownloadSize, 0, 0, 0, '', $alreadyImportedSize, $alreadyImportedNumber, $importTree
				);
			} else {
				foreach ($importTree as $path => $state) {
					if ($state === 'todo') {
						$downloadResult = $this->downloadDir(
							$userId, $topFolder, $maxDownloadSize, 0, 0, 0, (string)$path, $alreadyImportedSize, $alreadyImportedNumber, $importTree
						);
					}
				}
			}
		} catch (MaxDownloadSizeReachedException $e) {
			return [
				'targetPath' => $targetPath,
				'finished' => false,
			];
		}

		return [
			'targetPath' => $targetPath,
			'finished' => true,
			'totalSeenNumber' => $downloadResult['totalSeenNumber'],
		];
	}

	private function downloadDir(string $userId, Folder $topFolder,
		?int $maxDownloadSize, int $downloadedSize, int $totalSeenNumber,
		int $nbDownloaded, string $path, float $alreadyImportedSize, int $alreadyImportedNumber,
		array &$importTree): array {
		$newDownloadedSize = $downloadedSize;
		$newTotalSeenNumber = $totalSeenNumber;
		$newNbDownloaded = $nbDownloaded;

		// create dir if needed
		if (!$topFolder->nodeExists($path)) {
			$folder = $topFolder->newFolder($path);
		} else {
			$folder = $topFolder->get($path);
		}

		$encPath = rawurlencode($path);
		$reqPath = ($encPath === '')
			? ''
			: ':' . $encPath . ':';
		$endPoint = 'me/drive/root' . $reqPath . '/children';
		$params = [];
		do {
			$result = $this->onedriveApiService->request($userId, $endPoint, $params);
			if (isset($result['error']) || !isset($result['value']) || !is_array($result['value'])) {
				return [
					'downloadedSize' => $newDownloadedSize,
					'totalSeenNumber' => $newTotalSeenNumber,
					'nbDownloaded' => $newNbDownloaded,
				];
			}
			// first get all files
			foreach ($result['value'] as $item) {
				if (isset($item['file'])) {
					$newTotalSeenNumber++;
					$size = $this->getFile($folder, $item);
					$newDownloadedSize += ($size ?? 0);
					if (!is_null($size) && $size > 0) {
						$newNbDownloaded++;
						$this->config->setUserValue($userId, Application::APP_ID, 'imported_size', (string)($alreadyImportedSize + $newDownloadedSize));
						$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', (string)($alreadyImportedNumber + $newNbDownloaded));
						$ts = (new DateTime())->getTimestamp();
						$this->config->setUserValue($userId, Application::APP_ID, 'last_onedrive_import_timestamp', (string)$ts);
					}
					if (!is_null($maxDownloadSize) && $newDownloadedSize >= $maxDownloadSize) {
						throw new MaxDownloadSizeReachedException('Yep');
					}
				}
			}
			// REMOVE this directory from unfinished list
			if (array_key_exists($path, $importTree)) {
				unset($importTree[$path]);
			}
			if (isset($result['@odata.nextLink'])
				&& $result['@odata.nextLink']
				&& preg_match('/\$skiptoken=/i', $result['@odata.nextLink'])) {
				$params['$skiptoken'] = preg_replace('/.*\$skiptoken=/', '', $result['@odata.nextLink']);
			}
		} while (isset($result['@odata.nextLink']) && $result['@odata.nextLink']);

		// DIRECTORIES
		$params = [
			'filter' => 'folder ne null',
		];
		do {
			$result = $this->onedriveApiService->request($userId, $endPoint, $params);
			if (isset($result['error']) || !isset($result['value']) || !is_array($result['value'])) {
				return [
					'downloadedSize' => $newDownloadedSize,
					'totalSeenNumber' => $newTotalSeenNumber,
					'nbDownloaded' => $newNbDownloaded,
				];
			}
			// store directories we have to do
			foreach ($result['value'] as $item) {
				if (isset($item['folder'])) {
					$subDirPath = $path . '/' . $item['name'];
					$importTree[$subDirPath] = 'todo';
				}
			}
			// then explore sub directories
			foreach ($result['value'] as $item) {
				if (isset($item['folder'])) {
					$subDownloadResult = $this->downloadDir(
						$userId, $topFolder, $maxDownloadSize, (int)$newDownloadedSize, (int)$newTotalSeenNumber, (int)$newNbDownloaded,
						$path . '/' . $item['name'], $alreadyImportedSize, $alreadyImportedNumber, $importTree
					);
					$newDownloadedSize = $subDownloadResult['downloadedSize'];
					$newTotalSeenNumber = $subDownloadResult['totalSeenNumber'];
					$newNbDownloaded = $subDownloadResult['nbDownloaded'];
				}
			}
			if (isset($result['@odata.nextLink'])
				&& $result['@odata.nextLink']
				&& preg_match('/\$skiptoken=/i', $result['@odata.nextLink'])) {
				$params['$skiptoken'] = preg_replace('/.*\$skiptoken=/', '', $result['@odata.nextLink']);
			}
		} while (isset($result['@odata.nextLink']) && $result['@odata.nextLink']);

		// update last modified date when directory is fully downloaded (because creating children triggers a touch as well)
		$this->touchFolder($userId, $folder, $path);

		return [
			'downloadedSize' => $newDownloadedSize,
			'totalSeenNumber' => $newTotalSeenNumber,
			'nbDownloaded' => $newNbDownloaded,
		];
	}

	/**
	 * @param string $userId
	 * @param Folder $folder
	 * @param string $onedrivePath
	 * @return void
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 * @throws PreConditionNotMetException
	 */
	private function touchFolder(string $userId, Folder $folder, string $onedrivePath): void {
		$encPath = rawurlencode($onedrivePath);
		$reqPath = ($encPath === '')
			? ''
			: ':' . $encPath . ':';
		$endPoint = 'me/drive/root' . $reqPath;
		$remoteFolderInfo = $this->onedriveApiService->request($userId, $endPoint);
		if (isset($remoteFolderInfo['lastModifiedDateTime'])) {
			$d = new DateTime($remoteFolderInfo['lastModifiedDateTime']);
			$ts = $d->getTimestamp();
			$folder->touch($ts);
		}
	}

	/**
	 * @param array $fileItem
	 * @param Folder $folder
	 * @return ?float downloaded size, null if already existing or network error
	 */
	private function getFile(Folder $folder, array $fileItem): ?float {
		$fileName = $fileItem['name'];
		try {
			$fileExists = $folder->nodeExists($fileName);
		} catch (ForbiddenException $e) {
			return null;
		}
		if (!$fileExists) {
			$savedFile = $folder->newFile($fileName);
			$resource = $savedFile->fopen('w');
			if ($resource === false) {
				$this->logger->warning('Could not open new file for writing', ['app' => Application::APP_ID]);
				if ($savedFile->isDeletable()) {
					$savedFile->delete();
				}
				return null;
			}
			$res = $this->onedriveApiService->fileRequest($fileItem['@microsoft.graph.downloadUrl'], $resource);
			if (is_resource($resource)) {
				fclose($resource);
			}
			if (!isset($res['error'])) {
				if (isset($fileItem['lastModifiedDateTime'])) {
					$d = new DateTime($fileItem['lastModifiedDateTime']);
					$ts = $d->getTimestamp();
					$savedFile->touch($ts);
				} else {
					$savedFile->touch();
				}
				$stat = $savedFile->stat();
				return (float)($stat['size'] ?? 0);
			} else {
				// there was an error
				$this->logger->warning('OneDrive error downloading file ' . $fileName . ' : ' . $res['error'], ['app' => Application::APP_ID]);
				if ($savedFile->isDeletable()) {
					$savedFile->delete();
				}
				return null;
			}
		} else {
			// file exists
			return 0;
		}
	}
}
