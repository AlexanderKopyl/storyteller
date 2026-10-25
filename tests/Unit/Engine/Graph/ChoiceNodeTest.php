<?php

declare(strict_types=1);

namespace Storyteller\Tests\Unit\Engine\Graph;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Storyteller\Engine\Ast\Effect;
use Storyteller\Engine\Ast\HasItem;
use Storyteller\Engine\Exception\MalformedNodeException;
use Storyteller\Engine\Graph\ChoiceNode;

/**
 * An authored choice as it appears in the compiled snapshot.
 */
#[CoversClass(ChoiceNode::class)]
final class ChoiceNodeTest extends TestCase
{
    public function testIsAlwaysAvailableWhenNoCondition(): void
    {
        $guarded = ChoiceNode::fromArray([
            'key' => 'north',
            'condition' => ['op' => 'has_item', 'item' => 'lantern'],
            'effects' => [['op' => 'sub', 'var' => 'hp', 'value' => 1]],
        ]);
        $open = ChoiceNode::fromArray(['key' => 'south']);

        self::assertFalse($guarded->isAlwaysAvailable());
        self::assertInstanceOf(HasItem::class, $guarded->condition);
        self::assertCount(1, $guarded->effects);

        self::assertTrue($open->isAlwaysAvailable());
        self::assertNull($open->condition);
        self::assertSame([], $open->effects);
    }

    /**
     * M3: the two cases must not be confused. An **omitted** `condition` key
     * means "no guard, always available"; an **explicit null** is malformed
     * content — a YAML `condition:` line with nothing after it, or a compiler
     * emitting `"condition": null` — and silently reading it as "no guard"
     * would delete an authored gate rather than report an authoring error.
     */
    public function testAnOmittedConditionIsAlwaysAvailableButAnExplicitNullIsRejected(): void
    {
        $omitted = ChoiceNode::fromArray(['key' => 'south']);

        self::assertTrue($omitted->isAlwaysAvailable());

        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('choice: key "condition" must be of type object, got null.');

        ChoiceNode::fromArray(['key' => 'south', 'condition' => null]);
    }

    public function testRoundTrips(): void
    {
        $node = [
            'key' => 'north',
            'condition' => ['op' => 'has_item', 'item' => 'lantern', 'qty' => 1],
            'effects' => [['op' => 'sub', 'var' => 'hp', 'value' => 1]],
        ];

        $choice = ChoiceNode::fromArray($node);

        self::assertSame($node, $choice->toArray());
        self::assertEquals($choice, ChoiceNode::fromArray($choice->toArray()));
        self::assertSame(['key' => 'south', 'effects' => []], ChoiceNode::fromArray(['key' => 'south'])->toArray());
    }

    public function testRejectsAMissingKey(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('key');

        ChoiceNode::fromArray(['effects' => []]);
    }

    public function testRejectsAnUnknownKey(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('label');

        ChoiceNode::fromArray(['key' => 'north', 'label' => 'Go north']);
    }

    public function testTheErrorPathNamesTheOffendingEffect(): void
    {
        $this->expectException(MalformedNodeException::class);
        $this->expectExceptionMessage('choice.effects[1]');

        ChoiceNode::fromArray([
            'key' => 'north',
            'effects' => [['op' => 'set_flag', 'flag' => 'a'], ['op' => 'set_flag']],
        ]);
    }

    public function testCanBeBuiltDirectly(): void
    {
        $choice = new ChoiceNode('north', null, Effect::listFromArray([['op' => 'set_flag', 'flag' => 'a']], 'e'));

        self::assertSame('north', $choice->key);
        self::assertTrue($choice->isAlwaysAvailable());
        self::assertCount(1, $choice->effects);
    }
}
