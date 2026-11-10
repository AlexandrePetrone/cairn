<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class CommentApiTest extends ApiTestCase
{
    public function testTheCommentsOfAnIssueAreListedOldestFirst(): void
    {
        $comments = $this->api('GET', '/api/issues/MD-SPT-4/comments', $this->tokenFor(self::DEVELOPER)->secret);

        self::assertResponseIsSuccessful();
        self::assertCount(3, $comments['member']);
        self::assertSame([false, false, true], array_column($comments['member'], 'private'));
        self::assertSame('MD-SPT-4', $comments['member'][0]['issue']);
        self::assertArrayHasKey('email', $comments['member'][0]['author']);
    }

    public function testACommentIsAddedEditedAndDeleted(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;

        $comment = $this->api('POST', '/api/issues/MD-SPT-1/comments', $secret, ['body' => 'Looking into it.', 'private' => true]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Looking into it.', $comment['body']);
        self::assertTrue($comment['private']);
        self::assertSame('Nadia Benali', $comment['author']['name']);
        $iri = $comment['@id'];
        self::assertSame('/api/comments/'.$comment['id'], $iri);

        $edited = $this->api('PATCH', $iri, $secret, ['body' => 'Found it.']);
        self::assertResponseIsSuccessful();
        self::assertSame('Found it.', $edited['body']);
        self::assertTrue($edited['private'], 'Editing a comment does not change who reads it.');
        self::assertArrayHasKey('editedAt', $edited);

        self::assertSame('Found it.', $this->api('GET', $iri, $secret)['body']);

        $this->api('DELETE', $iri, $secret);
        self::assertResponseStatusCodeSame(204);
        $this->api('GET', $iri, $secret);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheCommentOfSomeoneElseIsTheirs(): void
    {
        $comment = $this->api('POST', '/api/issues/MD-SPT-1/comments', $this->tokenFor(self::MANAGER)->secret, ['body' => 'From the manager.']);

        $this->api('PATCH', $comment['@id'], $this->tokenFor(self::DEVELOPER)->secret, ['body' => 'Rewritten.']);
        self::assertResponseStatusCodeSame(403);
        $this->api('DELETE', $comment['@id'], $this->tokenFor(self::DEVELOPER)->secret);
        self::assertResponseStatusCodeSame(403);
    }

    public function testACommentNeedsABodyAndAnIssue(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;

        $empty = $this->api('POST', '/api/issues/MD-SPT-1/comments', $secret, ['body' => '  ']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('body', $empty['violations'][0]['propertyPath']);

        $this->api('POST', '/api/issues/MD-SPT-999/comments', $secret, ['body' => 'Nowhere.']);
        self::assertResponseStatusCodeSame(404);

        $this->api('POST', '/api/issues/MD-SPT-1/comments', $this->tokenFor(self::OUTSIDER)->secret, ['body' => 'Not a member.']);
        self::assertResponseStatusCodeSame(403);
    }
}
