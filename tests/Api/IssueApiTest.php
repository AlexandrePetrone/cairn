<?php

declare(strict_types=1);

namespace App\Tests\Api;

final class IssueApiTest extends ApiTestCase
{
    public function testTheListIsFilteredLikeTheApplication(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;

        $open = $this->api('GET', '/api/issues?project=MD-SPT', $secret);
        self::assertResponseIsSuccessful();
        $keys = array_column($open['member'], 'key');
        self::assertContains('MD-SPT-1', $keys);
        self::assertContains('MD-SPT-6', $keys, 'An internal user reads the private tasks.');
        self::assertNotContains('MD-SPT-5', $keys, 'Finished issues are left out by default.');
        self::assertSame(\count($keys), $open['totalItems']);

        $all = $this->api('GET', '/api/issues?project=MD-SPT&closed=1', $secret);
        self::assertContains('MD-SPT-5', array_column($all['member'], 'key'));

        $new = $this->api('GET', '/api/issues?project=MD-SPT&place[]=bug:new&sort=key', $secret);
        self::assertSame(['MD-SPT-1', 'MD-SPT-2'], array_column($new['member'], 'key'));
        self::assertSame(['@type' => 'PlaceRef', 'id' => 'new', 'label' => 'New'], $new['member'][0]['place']);
        self::assertArrayNotHasKey('transitions', $new['member'][0], 'A list does not carry the detail.');

        $paged = $this->api('GET', '/api/issues?project=MD-SPT&closed=1&sort=key&itemsPerPage=3&page=2', $secret);
        self::assertSame(['MD-SPT-4', 'MD-SPT-5', 'MD-SPT-6'], array_column($paged['member'], 'key'));
        self::assertSame(10, $paged['totalItems']);
    }

    public function testAFilterTheApiDoesNotUnderstandIsRefused(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;

        foreach (['project=XX-YY', 'criticality[]=urgent', 'sort=color', 'sort=rank', 'tracker[]=bug', 'assignee[]=nobody'] as $filter) {
            $this->api('GET', '/api/issues?'.$filter, $secret);
            // 422 when API Platform refuses the value against the schema of the parameter, 400 when the provider does.
            self::assertContains($this->client->getResponse()->getStatusCode(), [400, 422], $filter);
        }
    }

    public function testAnIssueComesWithItsFieldsAndTheActionsOffered(): void
    {
        $issue = $this->api('GET', '/api/issues/MD-SPT-1', $this->tokenFor(self::DEVELOPER)->secret);

        self::assertResponseIsSuccessful();
        self::assertSame('/api/issues/MD-SPT-1', $issue['@id']);
        self::assertSame('MD-SPT', $issue['project']);
        self::assertSame('http://localhost/MD/SPT/issues/1', $issue['url']);
        self::assertFalse($issue['closed']);
        self::assertContains('environment', array_column($issue['fields'], 'id'));
        self::assertSame(['qualify', 'reject'], array_column($issue['transitions'], 'id'));
        self::assertSame(['@type' => 'PlaceRef', 'id' => 'qualified', 'label' => 'Qualified'], $issue['transitions'][0]['to']);
    }

    public function testWhatDoesNotExistIsNotFound(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;

        foreach (['MD-SPT-999', 'MD-XXX-1', 'nonsense', 'MD-SPT-0'] as $key) {
            $this->api('GET', '/api/issues/'.$key, $secret);
            self::assertResponseStatusCodeSame(404, $key);
        }
    }

    public function testAnIssueIsCreated(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;

        $issue = $this->api('POST', '/api/issues', $secret, [
            'project' => 'MD-SPT',
            'tracker' => $this->scene->bugs->getId(),
            'title' => 'The basket forgets its content',
            'description' => 'After **signing in**.',
            'criticality' => 'high',
            'fields' => ['environment' => 'prod'],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('MD-SPT-11', $issue['key']);
        self::assertSame('high', $issue['criticality']);
        self::assertSame('Nadia Benali', $issue['author']['name']);
        self::assertSame('The basket forgets its content', $this->issue('MD-SPT-11')->getTitle());
    }

    public function testAnIssueThatCannotBeCreatedSaysWhy(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;

        $missingTitle = $this->api('POST', '/api/issues', $secret, ['project' => 'MD-SPT', 'tracker' => $this->scene->bugs->getId()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('title', $missingTitle['violations'][0]['propertyPath']);

        $missingField = $this->api('POST', '/api/issues', $secret, ['project' => 'MD-SPT', 'tracker' => $this->scene->bugs->getId(), 'title' => 'No environment']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('environment', strtolower($missingField['detail']));

        $this->api('POST', '/api/issues', $secret, ['project' => 'XX-YY', 'tracker' => 1, 'title' => 'Nowhere']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testOnlyThePropertiesSentChange(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;
        $nadia = $this->scene->user(self::DEVELOPER);

        $assigned = $this->api('PATCH', '/api/issues/MD-SPT-2', $secret, ['assignee' => $nadia->getId(), 'criticality' => 'blocking']);
        self::assertResponseIsSuccessful();
        self::assertSame('Nadia Benali', $assigned['assignee']['name']);
        self::assertSame('blocking', $assigned['criticality']);

        $kept = $this->api('PATCH', '/api/issues/MD-SPT-2', $secret, ['criticality' => 'low']);
        self::assertSame('Nadia Benali', $kept['assignee']['name'], 'A property left out keeps its value.');

        $unassigned = $this->api('PATCH', '/api/issues/MD-SPT-2', $secret, ['assignee' => null]);
        self::assertArrayNotHasKey('assignee', $unassigned);
        self::assertNull($this->issue('MD-SPT-2')->getAssignee());
    }

    public function testEachPropertyNeedsItsOwnRight(): void
    {
        $title = $this->scene->issue('MD-SPT-2')->getTitle();

        // A developer assigns, but does not rewrite the issue of someone else.
        $this->api('PATCH', '/api/issues/MD-SPT-2', $this->tokenFor(self::DEVELOPER)->secret, ['title' => 'Mine now', 'criticality' => 'low']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($title, $this->issue('MD-SPT-2')->getTitle());

        $renamed = $this->api('PATCH', '/api/issues/MD-SPT-2', $this->tokenFor(self::MANAGER)->secret, ['title' => 'Renamed by the manager']);
        self::assertResponseIsSuccessful();
        self::assertSame('Renamed by the manager', $renamed['title']);
    }

    public function testAnActionMovesTheIssue(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;

        $issue = $this->api('POST', '/api/issues/MD-SPT-1/transitions', $secret, ['transition' => 'qualify', 'comment' => 'Reproduced.']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('qualified', $issue['place']['id']);
        self::assertSame(['reject', 'start'], array_column($issue['transitions'], 'id'));

        $refused = $this->api('POST', '/api/issues/MD-SPT-1/transitions', $secret, ['transition' => 'deliver']);
        self::assertResponseStatusCodeSame(422, 'An action the place does not offer.');
        self::assertNotEmpty($refused['detail']);

        $this->api('POST', '/api/issues/MD-SPT-4/transitions', $secret, ['transition' => 'accept']);
        self::assertResponseStatusCodeSame(403, 'An action of another role.');

        $this->api('POST', '/api/issues/MD-SPT-999/transitions', $secret, ['transition' => 'qualify']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAStepIsReachedByTheWorkflowFirstAndOutsideItOtherwise(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;

        $issue = $this->api('PUT', '/api/issues/MD-SPT-1/step', $secret, ['place' => 'qualified']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('qualified', $issue['place']['id']);
        $history = $this->api('GET', '/api/issues/MD-SPT-1/history', $secret);
        self::assertSame('transitioned', array_last($history['member'])['type'], '"Qualify" leads there: the action is applied');

        $issue = $this->api('PUT', '/api/issues/MD-SPT-1/step', $secret, ['place' => 'done']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('done', $issue['place']['id']);
        $history = $this->api('GET', '/api/issues/MD-SPT-1/history', $secret);
        self::assertSame('moved', array_last($history['member'])['type'], 'no action of a developer leads there: issue.force_place');

        $issue = $this->api('PUT', '/api/issues/MD-SPT-1/step', $secret, ['place' => 'done']);
        self::assertResponseStatusCodeSame(200, 'A PUT may be repeated.');
        self::assertSame('done', $issue['place']['id']);
        self::assertCount(\count($history['member']), $this->api('GET', '/api/issues/MD-SPT-1/history', $secret)['member'], 'nothing more happened');

        $this->api('PUT', '/api/issues/MD-SPT-1/step', $secret, ['place' => 'nowhere']);
        self::assertResponseStatusCodeSame(422, 'A place the workflow does not have.');

        $this->api('PUT', '/api/issues/MD-SPT-1/step', $secret, []);
        self::assertResponseStatusCodeSame(422, 'No place.');

        $this->api('PUT', '/api/issues/MD-SPT-999/step', $secret, ['place' => 'done']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnIssueChangesTrackerOnAStepOfTheNewWorkflow(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;
        $feature = $this->scene->evolutions->getId();

        $issue = $this->api('PUT', '/api/issues/MD-SPT-1/tracker', $secret, ['tracker' => $feature, 'place' => 'to_estimate']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($feature, $issue['tracker']['id']);
        self::assertSame('to_estimate', $issue['place']['id']);
        $history = $this->api('GET', '/api/issues/MD-SPT-1/history', $secret);
        self::assertSame('tracker_changed', array_last($history['member'])['type']);

        $issue = $this->api('PUT', '/api/issues/MD-SPT-1/tracker', $secret, ['tracker' => $feature, 'place' => 'to_estimate']);
        self::assertResponseStatusCodeSame(200, 'A PUT may be repeated.');
        self::assertSame('to_estimate', $issue['place']['id']);
        self::assertCount(\count($history['member']), $this->api('GET', '/api/issues/MD-SPT-1/history', $secret)['member'], 'nothing more happened');

        $this->api('PUT', '/api/issues/MD-SPT-1/tracker', $secret, ['tracker' => $feature, 'place' => 'planned']);
        self::assertResponseStatusCodeSame(422, 'Already in that tracker: another step is a move, not a change of tracker.');

        $this->api('PUT', '/api/issues/MD-SPT-2/tracker', $secret, ['tracker' => $feature, 'place' => 'qualified']);
        self::assertResponseStatusCodeSame(422, 'A place of the former workflow.');

        $this->api('PUT', '/api/issues/MD-SPT-2/tracker', $secret, ['place' => 'new']);
        self::assertResponseStatusCodeSame(422, 'No tracker.');

        $this->api('PUT', '/api/issues/MD-SPT-999/tracker', $secret, ['tracker' => $feature, 'place' => 'new']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnInternalUserWithoutARoleReadsButDoesNotWrite(): void
    {
        $secret = $this->tokenFor(self::OUTSIDER)->secret;

        $this->api('GET', '/api/issues/MD-SPT-1', $secret);
        self::assertResponseIsSuccessful();

        $this->api('POST', '/api/issues/MD-SPT-1/transitions', $secret, ['transition' => 'qualify']);
        self::assertResponseStatusCodeSame(403, 'No action is offered to someone without a role.');

        $this->api('PATCH', '/api/issues/MD-SPT-1', $secret, ['criticality' => 'low']);
        self::assertResponseStatusCodeSame(403);
    }
}
