<?php

declare(strict_types=1);


/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Circles\Tools\Traits;

use Exception;
use GuzzleHttp\Exception\ClientException;
use OCA\Circles\Tools\Exceptions\RequestNetworkException;
use OCA\Circles\Tools\Model\NCRequest;
use OCA\Circles\Tools\Model\NCRequestResult;
use OCA\Circles\Tools\Model\Request;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Server;

trait TNCRequest {
	use TNCLogger;


	/**
	 * @param int $size
	 */
	public function setMaxDownloadSize(int $size) {
	}


	/**
	 * @param NCRequest $request
	 *
	 * @return array
	 * @throws RequestNetworkException
	 */
	public function retrieveJson(NCRequest $request): array {
		$this->doRequest($request);
		$requestResult = $request->getResult();

		return $requestResult->getAsArray();
	}


	/**
	 * @param NCRequest $request
	 * @param bool $exceptionOnIssue
	 *
	 * @throws RequestNetworkException
	 */
	public function doRequest(NCRequest $request, bool $exceptionOnIssue = true): void {
		$request->setClient(
			$this->clientService()
				->newClient()
		);

		$this->generationClientOptions($request);

		$this->debug('doRequest initiated', ['request' => $request]);
		foreach ($request->getProtocols() as $protocol) {
			$request->setUsedProtocol($protocol);
			try {
				$response = $this->useClient($request);
				$request->setResult(new NCRequestResult($response));
				break;
			} catch (ClientException $e) {
				$request->setResult(new NCRequestResult(null, $e));
			} catch (Exception $e) {
				$this->exception($e, self::$DEBUG, ['request' => $request]);
			}
		}

		$this->debug('doRequest done', ['request' => $request]);

		if ($exceptionOnIssue && (!$request->hasResult() || $request->getResult()->hasException())) {
			throw new RequestNetworkException();
		}
	}


	/**
	 * @return IClientService
	 */
	public function clientService(): IClientService {
		if (isset($this->clientService) && $this->clientService instanceof IClientService) {
			return $this->clientService;
		} else {
			return Server::get(IClientService::class);
		}
	}


	/**
	 * @param NCRequest $request
	 */
	private function generationClientOptions(NCRequest $request) {
		$options = [
			'headers' => $request->getHeaders(),
			'cookies' => $request->getCookies(),
			'verify' => $request->isVerifyPeer(),
			'timeout' => $request->getTimeout(),
			'http_errors' => !$request->isHttpErrorsAllowed()
		];

		if (!empty($request->getData())) {
			$options['body'] = $request->getDataBody();
		}

		if (!empty($request->getParams())) {
			$options['form_params'] = $request->getParams();
		}

		if ($request->isLocalAddressAllowed()) {
			$options['nextcloud']['allow_local_address'] = true;
		}

		if ($request->isFollowLocation()) {
			$options['allow_redirects'] = [
				'max' => 10,
				'strict' => true,
				'referer' => true,
			];
		} else {
			$options['allow_redirects'] = false;
		}

		$request->setClientOptions($options);
	}


	/**
	 * @param NCRequest $request
	 *
	 * @return IResponse
	 * @throws Exception
	 */
	private function useClient(NCRequest $request): IResponse {
		$client = $request->getClient();
		switch ($request->getType()) {
			case Request::TYPE_POST:
				return $client->post($request->getCompleteUrl(), $request->getClientOptions());
			case Request::TYPE_PUT:
				return $client->put($request->getCompleteUrl(), $request->getClientOptions());
			case Request::TYPE_DELETE:
				return $client->delete($request->getCompleteUrl(), $request->getClientOptions());
			case Request::TYPE_GET:
				return $client->get(
					$request->getCompleteUrl() . $request->getQueryString(), $request->getClientOptions()
				);
			default:
				throw new Exception('unknown request type ' . json_encode($request));
		}
	}
}
