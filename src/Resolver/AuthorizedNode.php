<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use GraphQL\Type\Definition\ResolveInfo;
use Wedrix\Watchtower\Plugins;

use function Wedrix\Watchtower\NodeAuthorizorPlugin;
use function Wedrix\Watchtower\RootNodeAuthorizorPlugin;

trait AuthorizedNode
{
    public function __construct(
        Plugins $plugins
    ) {
        $rootNodeAuthorizorPlugin = RootNodeAuthorizorPlugin();

        if ($plugins->contains($rootNodeAuthorizorPlugin)) {
            require_once $plugins->filePath($rootNodeAuthorizorPlugin);

            $rootNodeAuthorizorPlugin->callback()($this);
        }

        $nodeAuthorizorPlugin = NodeAuthorizorPlugin(
            nodeType: $this->unwrappedType()
        );

        if ($plugins->contains($nodeAuthorizorPlugin)) {
            require_once $plugins->filePath($nodeAuthorizorPlugin);

            $nodeAuthorizorPlugin->callback()($this);
        }
    }
}

/**
 * @param  array<string,mixed>  $root
 * @param  array<string,mixed>  $args
 * @param  array<string,mixed>  $context
 */
function AuthorizedNode(
    array $root,
    array $args,
    array $context,
    ResolveInfo $info,
    EntityManager $entityManager,
    Plugins $plugins
): Node {
    return new class(root: $root, args: $args, context: $context, info: $info, entityManager: $entityManager, plugins: $plugins) implements Node
    {
        use AuthorizedNode, BaseNode {
            AuthorizedNode::__construct as private _constructAuthorizedNode;
            BaseNode::__construct as private _constructBaseNode;
        }

        /**
         * @param  array<string,mixed>  $root
         * @param  array<string,mixed>  $args
         * @param  array<string,mixed>  $context
         */
        public function __construct(
            array $root,
            array $args,
            array $context,
            ResolveInfo $info,
            EntityManager $entityManager,
            Plugins $plugins
        ) {
            $this->_constructBaseNode(
                root: $root,
                args: $args,
                context: $context,
                info: $info,
                entityManager: $entityManager
            );

            $this->_constructAuthorizedNode(
                plugins: $plugins
            );
        }
    };
}
