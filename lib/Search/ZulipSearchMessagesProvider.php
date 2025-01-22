<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2024, Edward Ly
 *
 * @author Edward Ly <contact@edward.ly>
 *
 * @license AGPL-3.0-or-later
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\Zulip\Search;

use OCA\Zulip\AppInfo\Application;
use OCA\Zulip\Service\SecretService;
use OCA\Zulip\Service\ZulipAPIService;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

class ZulipSearchMessagesProvider implements IProvider {

	public function __construct(
		private IAppManager $appManager,
		private IL10N $l10n,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private IDateTimeFormatter $dateTimeFormatter,
		private IDateTimeZone $dateTimeZone,
		private SecretService $secretService,
		private ZulipAPIService $apiService
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string {
		return 'zulip-search-messages';
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->l10n->t('Zulip messages');
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(string $route, array $routeParameters): int {
		if (strpos($route, Application::APP_ID . '.') === 0) {
			// Active app, prefer Zulip results
			return -1;
		}

		return 20;
	}

	/**
	 * @inheritDoc
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		if (!$this->appManager->isEnabledForUser(Application::APP_ID, $user)) {
			return SearchResult::complete($this->getName(), []);
		}

		$limit = $query->getLimit();
		$term = $query->getTerm();
		$offset = $query->getCursor();
		$offset = $offset ? intval($offset) : 0;

		$url = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'url');
		$email = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'email');
		$apiKey = $this->secretService->getEncryptedUserValue($user->getUID(), 'api_key');
		$searchMessagesEnabled = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'search_messages_enabled', '0') === '1';
		if ($url === '' || $email === '' || $apiKey === '' || !$searchMessagesEnabled) {
			return SearchResult::paginated($this->getName(), [], 0);
		}

		$searchResult = $this->apiService->searchMessages($user->getUID(), $term, $offset, $limit);
		if (isset($searchResult['error'])) {
			return SearchResult::paginated($this->getName(), [], 0);
		}

		$formattedResults = array_map(function (array $entry) use ($url): SearchResultEntry {
			$finalThumbnailUrl = $this->getThumbnailUrl($entry);
			return new SearchResultEntry(
				$finalThumbnailUrl,
				$this->getMainText($entry),
				$this->getSubline($entry),
				$this->getLinkToZulip($entry, $url),
				$finalThumbnailUrl === '' ? 'icon-zulip-search-fallback' : '',
				true
			);
		}, $searchResult);

		return SearchResult::paginated(
			$this->getName(),
			$formattedResults,
			$offset + $limit
		);
	}

	/**
	 * @param array $entry
	 * @return string
	 */
	protected function getMainText(array $entry): string {
		return strip_tags($entry['content']);
	}

	/**
	 * @param array $entry
	 * @return string
	 */
	protected function getSubline(array $entry): string {
		if ($entry['type'] === 'stream') {
			return $this->l10n->t('%s in #%s > %s at %s', [$entry['sender_full_name'], $entry['display_recipient'], $entry['subject'], $this->getFormattedDate($entry['timestamp'])]);
		}

		$recipients = array_map(fn (array $user): string => $user['full_name'], $entry['display_recipient']);
		$displayRecipients = '@' . $recipients[0] . (count($recipients) > 1 ? ' (+' . strval(count($recipients) - 1) . ')' : '');
		return $this->l10n->t('%s in %s at %s', [$entry['sender_full_name'], $displayRecipients, $this->getFormattedDate($entry['timestamp'])]);
	}

	protected function getFormattedDate(int $timestamp): string {
		return $this->dateTimeFormatter->formatDateTime($timestamp, 'long', 'short', $this->dateTimeZone->getTimeZone());
	}

	/**
	 * @param array $entry
	 * @param string $url
	 * @return string
	 */
	protected function getLinkToZulip(array $entry, string $url): string {
		if ($entry['type'] === 'private') {
			$userIds = array_map(fn (array $recipient): string => strval($recipient['id']), $entry['display_recipient']);
			return rtrim($url, '/') . '/#narrow/dm/' . implode(',', $userIds) . '/near/' . $entry['id'];
		}

		$topic = str_replace('%', '.', rawurlencode($entry['subject']));
		return rtrim($url, '/') . '/#narrow/channel/' . $entry['stream_id'] . '/topic/' . $topic . '/near/' . $entry['id'];
	}

	/**
	 * @param array $entry
	 * @return string
	 */
	protected function getThumbnailUrl(array $entry): string {
		return '';
		// $senderId = $entry['sender_id'] ?? '';
		// return $senderId
		// 	? $this->urlGenerator->getAbsoluteURL(
		// 		$this->urlGenerator->linkToRoute('integration_zulip.zulipAPI.getUserAvatar', ['zulipUserId' => $senderId])
		// 	)
		// 	: '';
	}
}
