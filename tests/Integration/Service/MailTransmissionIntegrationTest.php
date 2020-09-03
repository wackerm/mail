<?php

declare(strict_types=1);

/**
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * Mail
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Mail\Tests\Integration\Service;

use ChristophWurst\Nextcloud\Testing\TestUser;
use OC;
use OCA\Mail\Account;
use OCA\Mail\Contracts\IAttachmentService;
use OCA\Mail\Contracts\IMailTransmission;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Db\MailAccountMapper;
use OCA\Mail\Db\MailboxMapper;
use OCA\Mail\Db\Message;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\IMAP\MailboxSync;
use OCA\Mail\IMAP\MessageMapper;
use OCA\Mail\Model\NewMessageData;
use OCA\Mail\Model\RepliedMessageData;
use OCA\Mail\Service\Attachment\UploadedFile;
use OCA\Mail\Service\MailTransmission;
use OCA\Mail\SMTP\SmtpClientFactory;
use OCA\Mail\Tests\Integration\Framework\ImapTest;
use OCA\Mail\Tests\Integration\TestCase;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ILogger;
use OCP\IUser;
use OCP\Security\ICrypto;

class MailTransmissionIntegrationTest extends TestCase {
	use ImapTest,
		TestUser;

	/** @var Account */
	private $account;

	/** @var IUser */
	private $user;

	/** @var IAttachmentService */
	private $attachmentService;

	/** @var IMailTransmission */
	private $transmission;

	protected function setUp(): void {
		parent::setUp();

		$this->resetImapAccount();
		$this->user = $this->createTestUser();

		/** @var ICrypto $crypo */
		$crypo = OC::$server->getCrypto();
		/** @var MailAccountMapper $mapper */
		$mapper = OC::$server->query(MailAccountMapper::class);
		$mailAccount = MailAccount::fromParams([
			'userId' => $this->user->getUID(),
			'email' => 'user@domain.tld',
			'inboundHost' => 'localhost',
			'inboundPort' => '993',
			'inboundSslMode' => 'ssl',
			'inboundUser' => 'user@domain.tld',
			'inboundPassword' => $crypo->encrypt('mypassword'),
			'outboundHost' => 'localhost',
			'outboundPort' => '2525',
			'outboundSslMode' => 'none',
			'outboundUser' => 'user@domain.tld',
			'outboundPassword' => $crypo->encrypt('mypassword'),
		]);
		$mapper->insert($mailAccount);

		$this->account = new Account($mailAccount);
		$this->attachmentService = OC::$server->query(IAttachmentService::class);
		$userFolder = OC::$server->getUserFolder($this->user->getUID());
		$this->transmission = new MailTransmission(
			$userFolder,
			$this->attachmentService,
			OC::$server->query(IMAPClientFactory::class),
			OC::$server->query(SmtpClientFactory::class),
			OC::$server->query(IEventDispatcher::class),
			OC::$server->query(MailboxMapper::class),
			OC::$server->query(MessageMapper::class),
			OC::$server->query(ILogger::class)
		);
	}

	public function testSendMail() {
		$message = NewMessageData::fromRequest($this->account, 'recipient@domain.com', null, null, 'greetings', 'hello there', []);

		$this->transmission->sendMessage($message, null);

		$this->addToAssertionCount(1);
	}

	public function testSendMailWithLocalAttachment() {
		$file = new UploadedFile([
			'name' => 'text.txt',
			'tmp_name' => dirname(__FILE__) . '/../../data/mail-message-123.txt',
		]);
		$this->attachmentService->addFile($this->user->getUID(), $file);
		$message = NewMessageData::fromRequest($this->account, 'recipient@domain.com', null, null, 'greetings', 'hello there', [
			[
				'isLocal' => 'true',
				'id' => 13,
			],
		]);

		$this->transmission->sendMessage($message, null);

		$this->addToAssertionCount(1);
	}

	public function testSendMailWithCloudAttachment() {
		$userFolder = OC::$server->getUserFolder($this->user->getUID());
		$userFolder->newFile('text.txt');
		$message = NewMessageData::fromRequest($this->account, 'recipient@domain.com', null, null, 'greetings', 'hello there', [
			[
				'isLocal' => false,
				'fileName' => 'text.txt',
			],
		]);

		$this->transmission->sendMessage($message, null);

		$this->addToAssertionCount(1);
	}

	public function testSendReply() {
		$mb = $this->getMessageBuilder();
		$originalMessage = $mb->from('from@domain.tld')
			->to('to@domain.tld')
			->subject('reply test')
			->finish();
		$originalUID = $this->saveMessage('inbox', $originalMessage);
		/** @var MailboxSync $mbSync */
		$mbSync = OC::$server->query(MailboxSync::class);
		$mbSync->sync($this->account, true);
		/** @var MailboxMapper $mailboxMapper */
		$mailboxMapper = OC::$server->query(MailboxMapper::class);
		$inbox = $mailboxMapper->find($this->account, 'INBOX');
		$messageInReply = new Message();
		$messageInReply->setUid($originalUID);
		$messageInReply->setMessageId('message@server');
		$messageInReply->setMailboxId($inbox->getId());

		$message = NewMessageData::fromRequest($this->account, 'recipient@domain.com', null, null, 'greetings', 'hello there', []);
		$reply = new RepliedMessageData($this->account, $messageInReply);
		$this->transmission->sendMessage($message, $reply);

		$this->assertMailboxExists('Sent');
		$this->assertMessageCount(1, 'Sent');
	}

	public function testSendReplyWithoutSubject() {
		$mb = $this->getMessageBuilder();
		$originalMessage = $mb->from('from@domain.tld')
			->to('to@domain.tld')
			->subject('reply test')
			->finish();
		$originalUID = $this->saveMessage('inbox', $originalMessage);
		/** @var MailboxSync $mbSync */
		$mbSync = OC::$server->query(MailboxSync::class);
		$mbSync->sync($this->account, true);
		/** @var MailboxMapper $mailboxMapper */
		$mailboxMapper = OC::$server->query(MailboxMapper::class);
		$inbox = $mailboxMapper->find($this->account, 'INBOX');
		$messageInReply = new Message();
		$messageInReply->setUid($originalUID);
		$messageInReply->setMessageId('message@server');
		$messageInReply->setMailboxId($inbox->getId());

		$message = NewMessageData::fromRequest($this->account, 'recipient@domain.com', null, null, '', 'hello there', []);
		$reply = new RepliedMessageData($this->account, $messageInReply);
		$this->transmission->sendMessage($message, $reply);

		$this->assertMailboxExists('Sent');
		$this->assertMessageCount(1, 'Sent');
	}

	public function testSendReplyWithoutReplySubject() {
		$mb = $this->getMessageBuilder();
		$originalMessage = $mb->from('from@domain.tld')
			->to('to@domain.tld')
			->subject('reply test')
			->finish();
		$originalUID = $this->saveMessage('inbox', $originalMessage);
		/** @var MailboxSync $mbSync */
		$mbSync = OC::$server->query(MailboxSync::class);
		$mbSync->sync($this->account, true);
		/** @var MailboxMapper $mailboxMapper */
		$mailboxMapper = OC::$server->query(MailboxMapper::class);
		$inbox = $mailboxMapper->find($this->account, 'INBOX');
		$messageInReply = new Message();
		$messageInReply->setUid($originalUID);
		$messageInReply->setMessageId('message@server');
		$messageInReply->setMailboxId($inbox->getId());

		$message = NewMessageData::fromRequest($this->account, 'recipient@domain.com', null, null, 'Re: reply test', 'hello there', []);
		$reply = new RepliedMessageData($this->account, $messageInReply);
		$this->transmission->sendMessage($message, $reply);

		$this->assertMailboxExists('Sent');
		$this->assertMessageCount(1, 'Sent');
	}

	public function testSaveNewDraft() {
		$message = NewMessageData::fromRequest($this->account, 'recipient@domain.com', null, null, 'greetings', 'hello there', [], false);
		[,,$uid] = $this->transmission->saveDraft($message);
		// There should be a new mailbox …
		$this->assertMailboxExists('Drafts');
		// … and it should have exactly one message …
		$this->assertMessageCount(1, 'Drafts');
		// … and the correct content
		$this->assertMessageContent('Drafts', $uid, 'hello there');
	}

	public function testReplaceDraft() {
		$message1 = NewMessageData::fromRequest($this->account, 'recipient@domain.com', null, null, 'greetings', 'hello t', []);
		[,,$uid] = $this->transmission->saveDraft($message1);
		$message2 = NewMessageData::fromRequest($this->account, 'recipient@domain.com', null, null, 'greetings', 'hello there', []);
		$previous = new Message();
		$previous->setUid($uid);
		$this->transmission->saveDraft($message2, $previous);

		$this->assertMessageCount(1, 'Drafts');
	}
}
