<?php

declare(strict_types=1);

namespace Campanella\Tree;

use Campanella\Model\CampanellaObject;

/** A node of a tree built by TreeBuilder: the object and its children, in order. */
final class TreeNode
{
    /** @param list<TreeNode> $children */
    public function __construct(
        public readonly CampanellaObject $object,
        public array $children = [],
        public readonly int $level = 0,
    ) {
    }

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }
}
