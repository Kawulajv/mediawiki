<?php
/**
 *
 *
 * Created on Jun 30, 2007
 *
 * Copyright © 2007 Roan Kattouw "<Firstname>.<Lastname>@gmail.com"
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

/**
 * API module that facilitates deleting pages. The API equivalent of action=delete.
 * Requires API write mode to be enabled.
 *
 * @ingroup API
 */
class ApiDelete extends ApiBase {
	/**
	 * Extracts the title, token, and reason from the request parameters and invokes
	 * the local delete() function with these as arguments. It does not make use of
	 * the delete function specified by Article.php. If the deletion succeeds, the
	 * details of the article deleted and the reason for deletion are added to the
	 * result object.
	 */
	public function execute() {
		$params = $this->extractRequestParams();

		$pageObj = $this->getTitleOrPageId( $params, 'fromdbmaster' );
		if ( !$pageObj->exists() ) {
			$this->dieUsageMsg( 'notanarticle' );
		}

		$titleObj = $pageObj->getTitle();
		$reason = $params['reason'];
		$user = $this->getUser();

		if ( $titleObj->getNamespace() == NS_FILE ) {
			$status = self::deleteFile(
				$pageObj,
				$user,
				$params['token'],
				$params['oldimage'],
				$reason,
				false
			);
		} else {
			$status = self::delete( $pageObj, $user, $params['token'], $reason );
		}

		if ( is_array( $status ) ) {
			$this->dieUsageMsg( $status[0] );
		}
		if ( !$status->isGood() ) {
			$this->dieStatus( $status );
		}

		// Deprecated parameters
		if ( $params['watch'] ) {
			$this->logFeatureUsage( 'action=delete&watch' );
			$watch = 'watch';
		} elseif ( $params['unwatch'] ) {
			$this->logFeatureUsage( 'action=delete&unwatch' );
			$watch = 'unwatch';
		} else {
			$watch = $params['watchlist'];
		}
		$this->setWatch( $watch, $titleObj, 'watchdeletion' );

		$r = array(
			'title' => $titleObj->getPrefixedText(),
			'reason' => $reason,
			'logid' => $status->value
		);
		$this->getResult()->addValue( null, $this->getModuleName(), $r );
	}

	/**
	 * @param Title $title
	 * @param User $user User doing the action
	 * @param string $token
	 * @return array
	 */
	private static function getPermissionsError( $title, $user, $token ) {
		// Check permissions
		return $title->getUserPermissionsErrors( 'delete', $user );
	}

	/**
	 * We have our own delete() function, since Article.php's implementation is split in two phases
	 *
	 * @param Page|WikiPage $page Page or WikiPage object to work on
	 * @param User $user User doing the action
	 * @param string $token Delete token (same as edit token)
	 * @param string|null $reason Reason for the deletion. Autogenerated if null
	 * @return Status|array
	 */
	public static function delete( Page $page, User $user, $token, &$reason = null ) {
		$title = $page->getTitle();
		$errors = self::getPermissionsError( $title, $user, $token );
		if ( count( $errors ) ) {
			return $errors;
		}

		// Auto-generate a summary, if necessary
		if ( is_null( $reason ) ) {
			// Need to pass a throwaway variable because generateReason expects
			// a reference
			$hasHistory = false;
			$reason = $page->getAutoDeleteReason( $hasHistory );
			if ( $reason === false ) {
				return array( array( 'cannotdelete', $title->getPrefixedText() ) );
			}
		}

		$error = '';

		// Luckily, Article.php provides a reusable delete function that does the hard work for us
		return $page->doDeleteArticleReal( $reason, false, 0, true, $error );
	}

	/**
	 * @param Page $page Object to work on
	 * @param User $user User doing the action
	 * @param string $token Delete token (same as edit token)
	 * @param string $oldimage Archive name
	 * @param string $reason Reason for the deletion. Autogenerated if null.
	 * @param bool $suppress Whether to mark all deleted versions as restricted
	 * @return Status|array
	 */
	public static function deleteFile( Page $page, User $user, $token, $oldimage,
		&$reason = null, $suppress = false
	) {
		$title = $page->getTitle();
		$errors = self::getPermissionsError( $title, $user, $token );
		if ( count( $errors ) ) {
			return $errors;
		}

		$file = $page->getFile();
		if ( !$file->exists() || !$file->isLocal() || $file->getRedirected() ) {
			return self::delete( $page, $user, $token, $reason );
		}

		if ( $oldimage ) {
			if ( !FileDeleteForm::isValidOldSpec( $oldimage ) ) {
				return array( array( 'invalidoldimage' ) );
			}
			$oldfile = RepoGroup::singleton()->getLocalRepo()->newFromArchiveName( $title, $oldimage );
			if ( !$oldfile->exists() || !$oldfile->isLocal() || $oldfile->getRedirected() ) {
				return array( array( 'nodeleteablefile' ) );
			}
		}

		if ( is_null( $reason ) ) { // Log and RC don't like null reasons
			$reason = '';
		}

		return FileDeleteForm::doDelete( $title, $file, $oldimage, $reason, $suppress, $user );
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	public function getAllowedParams() {
		return array(
			'title' => null,
			'pageid' => array(
				ApiBase::PARAM_TYPE => 'integer'
			),
			'token' => array(
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true
			),
			'reason' => null,
			'watch' => array(
				ApiBase::PARAM_DFLT => false,
				ApiBase::PARAM_DEPRECATED => true,
			),
			'watchlist' => array(
				ApiBase::PARAM_DFLT => 'preferences',
				ApiBase::PARAM_TYPE => array(
					'watch',
					'unwatch',
					'preferences',
					'nochange'
				),
			),
			'unwatch' => array(
				ApiBase::PARAM_DFLT => false,
				ApiBase::PARAM_DEPRECATED => true,
			),
			'oldimage' => null,
		);
	}

	public function getParamDescription() {
		$p = $this->getModulePrefix();

		return array(
			'title' => "Title of the page you want to delete. Cannot be used together with {$p}pageid",
			'pageid' => "Page ID of the page you want to delete. Cannot be used together with {$p}title",
			'token' => 'A delete token previously retrieved through prop=info',
			'reason'
				=> 'Reason for the deletion. If not set, an automatically generated reason will be used',
			'watch' => 'Add the page to your watchlist',
			'watchlist' => 'Unconditionally add or remove the page from your ' .
				'watchlist, use preferences or do not change watch',
			'unwatch' => 'Remove the page from your watchlist',
			'oldimage' => 'The name of the old image to delete as provided by iiprop=archivename'
		);
	}

	public function getDescription() {
		return 'Delete a page.';
	}

	public function needsToken() {
		return true;
	}

	public function getTokenSalt() {
		return '';
	}

	public function getExamples() {
		return array(
			'api.php?action=delete&title=Main%20Page&token=123ABC' => 'Delete the Main Page',
			'api.php?action=delete&title=Main%20Page&token=123ABC&reason=Preparing%20for%20move'
				=> 'Delete the Main Page with the reason "Preparing for move"',
		);
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/API:Delete';
	}
}
