<?php

namespace App\Tests\Unit\ProofaMultiTenantBundle\Service;

use App\ProofaMultiTenantBundle\Service\WorkspaceIdValidator;
use PHPUnit\Framework\TestCase;

class WorkspaceIdValidatorTest extends TestCase
{
    private WorkspaceIdValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new WorkspaceIdValidator();
    }

    /**
     * @dataProvider validWorkspaceIdProvider
     */
    public function testValidWorkspaceIds(string $id): void
    {
        $this->assertTrue($this->validator->validate($id));
    }

    public static function validWorkspaceIdProvider(): array
    {
        return [
            'simple alphanumeric' => ['abc123'],
            'with dashes' => ['my-workspace'],
            'with underscores' => ['my_workspace'],
            'mixed' => ['My-Workspace_123'],
            'minimum length' => ['abc'],
            'maximum length' => [str_repeat('a', 64)],
            'uuid-like' => ['550e8400-e29b-41d4-a716-446655440000'],
        ];
    }

    /**
     * @dataProvider invalidWorkspaceIdProvider
     */
    public function testInvalidWorkspaceIds(string $id): void
    {
        $this->assertFalse($this->validator->validate($id));
    }

    public static function invalidWorkspaceIdProvider(): array
    {
        return [
            'too short' => ['ab'],
            'too long' => [str_repeat('a', 65)],
            'contains dots' => ['workspace.test'],
            'contains slashes' => ['workspace/test'],
            'contains backslashes' => ['workspace\\test'],
            'contains spaces' => ['work space'],
            'contains semicolon' => ['workspace;DROP'],
            'contains quotes' => ['workspace"test'],
            'empty string' => [''],
            'single char' => ['a'],
            'path traversal' => ['../public'],
            'sql injection attempt' => ['ws"; DROP SCHEMA public CASCADE; --'],
        ];
    }

    /**
     * @dataProvider reservedNamesProvider
     */
    public function testReservedSchemaNames(string $name): void
    {
        $this->assertFalse($this->validator->validate($name));
    }

    public static function reservedNamesProvider(): array
    {
        return [
            'public' => ['public'],
            'PUBLIC (case)' => ['PUBLIC'],
            'information_schema' => ['information_schema'],
            'pg_catalog' => ['pg_catalog'],
            'pg_toast' => ['pg_toast'],
        ];
    }

    public function testSanitizeRemovesInvalidChars(): void
    {
        $this->assertEquals('abc123', $this->validator->sanitize('abc!@#123'));
        $this->assertEquals('test-workspace_1', $this->validator->sanitize('test-workspace_1'));
    }
}
