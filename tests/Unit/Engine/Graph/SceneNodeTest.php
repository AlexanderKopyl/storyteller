<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Exception\ChoiceUnavailableException;
use Storyteller\Engine\Exception\MalformedNodeException;
use Storyteller\Engine\Graph\NextRule;
use Storyteller\Engine\Graph\SceneNode;

/**
 * The scene is the unit of the compiled snapshot: everything `src/Content`
 * must produce for the Engine to play a story is in this shape, so it is
 * parsed from the documented JSON literally and round-tripped back.
 */
#[CoversClass(SceneNode::class)]
final class SceneNodeTest extends TestCase
{
    public function testParsesAFullSceneFromItsDocumentedShape(): void
    {
        $scene = SceneNode::fromArray(self::documentedScene());

        self::assertSame('crossroads', $scene->key);
        self::assertCount(1, $scene->onEnter);
        self::assertCount(2, $scene->choices);
        self::assertSame('north', $scene->choices[0]->key);
        self::assertSame('south', $scene->choices[1]->key);
        self::assertSame('cave', $scene->next);
    }

    public function testOptionalKeysDefaultToEmpty(): void
    {
        $scene = SceneNode::fromArray(['key' => 'the_end']);

        self::assertSame([], $scene->onEnter);
        self::assertSame([], $scene->choices);
        self::assertSame([], $scene->next);
        self::assertSame(
            ['key' => 'the_end', 'on_enter' => [], 'choices' => [], 'next' => []],
            $scene->toArray(),
        );
    }

    public function testNextAcceptsBothAStringAndARuleList(): void
    {
        $plain = SceneNode::fromArray(['key' => 'a', 'next' => 'cave']);
        $rules = SceneNode::fromArray(['key' => 'a', 'next' => [
            ['scene' => 'cave', 'if' => ['op' => 'flag', 'flag' => 'brave']],
            ['scene' => 'home'],
        ]]);

        self::assertSame('cave', $plain->next);
        self::assertIsArray($rules->next);
        self::assertContainsOnlyInstancesOf(NextRule::class, $rules->next);
        self::assertSame(['cave', 'home'], array_map(
            static fn (NextRule $rule): string => $rule->scene,
            $rules->next,
        ));
    }

    public function testChoiceThrowsForAnAbsentKey(): void
    {
        $scene = SceneNode::fromArray(self::documentedScene());

        self::assertTrue($scene->hasChoice('north'));
        self::assertFalse($scene->hasChoice('west'));
        self::assertSame('north', $scene->choice('north')->key);

        $this->expectException(ChoiceUnavailableException::class);
        $this->expectExceptionMessage('west');

        $scene->choice('west');
    }

    public function testDuplicateChoiceKeysAreRejected(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('north');

        SceneNode::fromArray([
            'key' => 'crossroads',
            'choices' => [['key' => 'north'], ['key' => 'north']],
        ]);
    }

    public function testRoundTripsThroughToArray(): void
    {
        $scene = SceneNode::fromArray(self::documentedScene());

        self::assertSame(self::documentedScene(), $scene->toArray());
        self::assertEquals($scene, SceneNode::fromArray($scene->toArray()));

        $withRules = SceneNode::fromArray(['key' => 'a', 'next' => [['scene' => 'home']]]);

        self::assertSame(
            ['key' => 'a', 'on_enter' => [], 'choices' => [], 'next' => [['scene' => 'home']]],
            $withRules->toArray(),
        );
        self::assertEquals($withRules, SceneNode::fromArray($withRules->toArray()));
    }

    public function testRejectsAMissingKeyAndAnUnknownKey(): void
    {
        self::assertMalformed('key', static fn (): SceneNode => SceneNode::fromArray(['next' => 'cave']));
        self::assertMalformed('text', static fn (): SceneNode => SceneNode::fromArray([
            'key' => 'a',
            'text' => 'Once upon a time',
        ]));
    }

    public function testTheErrorPathNamesTheOffendingChild(): void
    {
        self::assertMalformed('scene.on_enter[0]', static fn (): SceneNode => SceneNode::fromArray([
            'key' => 'a',
            'on_enter' => [['op' => 'set_flag']],
        ]));
        self::assertMalformed('scene.choices[1]', static fn (): SceneNode => SceneNode::fromArray([
            'key' => 'a',
            'choices' => [['key' => 'north'], []],
        ]));
        self::assertMalformed('scene.next[0]', static fn (): SceneNode => SceneNode::fromArray([
            'key' => 'a',
            'next' => [[]],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private static function documentedScene(): array
    {
        return [
            'key' => 'crossroads',
            'on_enter' => [['op' => 'set_flag', 'flag' => 'saw_crossroads']],
            'choices' => [
                [
                    'key' => 'north',
                    'condition' => ['op' => 'has_item', 'item' => 'lantern', 'qty' => 1],
                    'effects' => [['op' => 'sub', 'var' => 'hp', 'value' => 1]],
                ],
                ['key' => 'south', 'effects' => []],
            ],
            'next' => 'cave',
        ];
    }

    /**
     * @param callable(): SceneNode $parse
     */
    private static function assertMalformed(string $expectedMessage, callable $parse): void
    {
        try {
            $parse();
        } catch (MalformedNodeException $exception) {
            self::assertStringContainsString($expectedMessage, $exception->getMessage());

            return;
        }

        self::fail(sprintf('Expected a MalformedNodeException mentioning "%s".', $expectedMessage));
    }
}
