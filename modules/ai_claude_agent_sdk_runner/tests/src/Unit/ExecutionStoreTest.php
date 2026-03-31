<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_claude_agent_sdk_runner\Unit;

use Drupal\ai_claude_agent_sdk_runner\Service\ExecutionStore;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the ExecutionStore service.
 *
 * @group ai_claude_agent_sdk_runner
 */
class ExecutionStoreTest extends TestCase {

  /**
   * Builds a mock select statement chain ending with fetchField().
   *
   * @param mixed $fetchFieldReturn
   *   The value fetchField() will return.
   *
   * @return \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  private function buildDbWithFetchField(mixed $fetchFieldReturn): Connection {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn($fetchFieldReturn);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);

    return $db;
  }

  /**
   * Builds a mock select statement chain ending with fetchObject().
   *
   * @param object|false $fetchObjectReturn
   *   The value fetchObject() will return. Use FALSE for "no row".
   *
   * @return \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  private function buildDbWithFetchObject(object|false $fetchObjectReturn): Connection {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchObject')->willReturn($fetchObjectReturn);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);

    return $db;
  }

  /**
   * Tests that create() stores initiator_uid in the data JSON.
   */
  public function testCreateIncludesInitiatorUid(): void {
    $capturedFields = NULL;

    $insert = $this->createMock(Insert::class);
    $insert->method('fields')
      ->willReturnCallback(function (array $fields) use (&$capturedFields, $insert) {
        $capturedFields = $fields;
        return $insert;
      });
    $insert->method('execute')->willReturn('1');

    $db = $this->createMock(Connection::class);
    $db->method('insert')->willReturn($insert);

    $store = new ExecutionStore($db);
    $store->create('q1', 'agent1', 'profile1', 'job1', 'tok123', 42);

    $this->assertNotNull($capturedFields);
    $data = json_decode($capturedFields['data'], TRUE);
    $this->assertSame('tok123', $data['callback_token']);
    $this->assertSame(42, $data['initiator_uid']);
  }

  /**
   * Tests that getInitiatorUid() returns the UID from data JSON.
   */
  public function testGetInitiatorUidReturnsUid(): void {
    $jsonData = json_encode(['callback_token' => 'tok', 'initiator_uid' => 7]);

    $store = new ExecutionStore($this->buildDbWithFetchField($jsonData));
    $result = $store->getInitiatorUid('q1');
    $this->assertSame(7, $result);
  }

  /**
   * Tests that getInitiatorUid() returns NULL when the key is absent.
   */
  public function testGetInitiatorUidReturnsNullWhenMissing(): void {
    $jsonData = json_encode(['callback_token' => 'tok']);

    $store = new ExecutionStore($this->buildDbWithFetchField($jsonData));
    $result = $store->getInitiatorUid('q1');
    $this->assertNull($result);
  }

  /**
   * Tests that getInitiatorUid() returns NULL when no row exists.
   */
  public function testGetInitiatorUidReturnsNullWhenNoRow(): void {
    $store = new ExecutionStore($this->buildDbWithFetchField(FALSE));
    $result = $store->getInitiatorUid('q1');
    $this->assertNull($result);
  }

  /**
   * Tests that isRunning() returns FALSE for stale records older than 10 min.
   */
  public function testIsRunningWithAgeCutoffIgnoresStaleRecords(): void {
    $staleCreated = time() - 700;
    $row = (object) ['status' => 'running', 'created' => (string) $staleCreated];

    $store = new ExecutionStore($this->buildDbWithFetchObject($row));
    $this->assertFalse($store->isRunning('job1'));
  }

  /**
   * Tests that isRunning() returns TRUE for a recent running record.
   */
  public function testIsRunningReturnsTrueForRecentRecord(): void {
    $row = (object) ['status' => 'running', 'created' => (string) time()];

    $store = new ExecutionStore($this->buildDbWithFetchObject($row));
    $this->assertTrue($store->isRunning('job1'));
  }

  /**
   * Tests that isRunning() returns FALSE when no record exists.
   */
  public function testIsRunningReturnsFalseWhenNoRow(): void {
    $store = new ExecutionStore($this->buildDbWithFetchObject(FALSE));
    $this->assertFalse($store->isRunning('job1'));
  }

  /**
   * Tests that isRunning() returns FALSE for completed status.
   */
  public function testIsRunningReturnsFalseForCompletedStatus(): void {
    $row = (object) ['status' => 'completed', 'created' => (string) time()];

    $store = new ExecutionStore($this->buildDbWithFetchObject($row));
    $this->assertFalse($store->isRunning('job1'));
  }

  /**
   * Tests that markPolled() writes the polled flag, preserving existing data.
   */
  public function testMarkPolledAndIsPolled(): void {
    $existingJson = json_encode(['callback_token' => 'tok']);
    $capturedUpdateFields = NULL;

    // Statement for the select (fetchField).
    $markStatement = $this->createMock(StatementInterface::class);
    $markStatement->method('fetchField')->willReturn($existingJson);

    $markSelect = $this->createMock(SelectInterface::class);
    $markSelect->method('fields')->willReturnSelf();
    $markSelect->method('condition')->willReturnSelf();
    $markSelect->method('execute')->willReturn($markStatement);

    $markUpdate = $this->createMock(Update::class);
    $markUpdate->method('fields')
      ->willReturnCallback(function (array $fields) use (&$capturedUpdateFields, $markUpdate) {
        $capturedUpdateFields = $fields;
        return $markUpdate;
      });
    $markUpdate->method('condition')->willReturnSelf();
    $markUpdate->method('execute')->willReturn(1);

    $markDb = $this->createMock(Connection::class);
    $markDb->method('select')->willReturn($markSelect);
    $markDb->method('update')->willReturn($markUpdate);

    $store = new ExecutionStore($markDb);
    $store->markPolled('q1');

    $this->assertNotNull($capturedUpdateFields);
    $writtenData = json_decode($capturedUpdateFields['data'], TRUE);
    $this->assertTrue($writtenData['polled']);
    // Existing data must be preserved (merge, not replace).
    $this->assertSame('tok', $writtenData['callback_token']);
  }

  /**
   * Tests that isPolled() returns TRUE when the flag is present.
   */
  public function testIsPolledReturnsTrueWhenSet(): void {
    $polledJson = json_encode(['callback_token' => 'tok', 'polled' => TRUE]);
    $store = new ExecutionStore($this->buildDbWithFetchField($polledJson));
    $this->assertTrue($store->isPolled('q1'));
  }

  /**
   * Tests that isPolled() returns FALSE when the flag is absent.
   */
  public function testIsPolledReturnsFalseWhenNotSet(): void {
    $json = json_encode(['callback_token' => 'tok']);
    $store = new ExecutionStore($this->buildDbWithFetchField($json));
    $this->assertFalse($store->isPolled('q1'));
  }

  /**
   * Tests that storeSessionId() merges instead of replacing existing data.
   */
  public function testStoreSessionIdMergesInsteadOfReplacing(): void {
    $existingJson = json_encode(['callback_token' => 'abc', 'initiator_uid' => 5]);
    $existingRow = (object) ['id' => 99, 'data' => $existingJson];
    $capturedUpdateFields = NULL;

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchObject')->willReturn($existingRow);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $update = $this->createMock(Update::class);
    $update->method('fields')
      ->willReturnCallback(function (array $fields) use (&$capturedUpdateFields, $update) {
        $capturedUpdateFields = $fields;
        return $update;
      });
    $update->method('condition')->willReturnSelf();
    $update->method('execute')->willReturn(1);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);
    $db->method('update')->willReturn($update);

    $store = new ExecutionStore($db);
    $store->storeSessionId('job1', 'session-xyz');

    $this->assertNotNull($capturedUpdateFields);
    $mergedData = json_decode($capturedUpdateFields['data'], TRUE);

    // All three keys must be present — merge, not replace.
    $this->assertSame('abc', $mergedData['callback_token']);
    $this->assertSame(5, $mergedData['initiator_uid']);
    $this->assertSame('session-xyz', $mergedData['session_id']);
  }

  /**
   * Tests that storeSessionId() inserts a new row when none exists.
   */
  public function testStoreSessionIdInsertsWhenNoRow(): void {
    $capturedInsertFields = NULL;

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchObject')->willReturn(FALSE);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $insert = $this->createMock(Insert::class);
    $insert->method('fields')
      ->willReturnCallback(function (array $fields) use (&$capturedInsertFields, $insert) {
        $capturedInsertFields = $fields;
        return $insert;
      });
    $insert->method('execute')->willReturn('1');

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);
    $db->method('insert')->willReturn($insert);

    $store = new ExecutionStore($db);
    $store->storeSessionId('job1', 'session-abc');

    $this->assertNotNull($capturedInsertFields);
    $data = json_decode($capturedInsertFields['data'], TRUE);
    $this->assertSame('session-abc', $data['session_id']);
    $this->assertSame('completed', $capturedInsertFields['status']);
  }

}
