<?php
namespace JmesPath;

/**
 * Tree visitor used to evaluates JMESPath AST expressions.
 */
class TreeInterpreter
{
    /**root data */
    private $root = null;

    /** @var callable */
    private $fnDispatcher;

    /**
     * @param callable $fnDispatcher Function dispatching function that accepts
     *                               a function name argument and an array of
     *                               function arguments and returns the result.
     */
    public function __construct(callable $fnDispatcher = null)
    {
        $this->fnDispatcher = $fnDispatcher ?: FnDispatcher::getInstance();
    }

    /**
     * Visits each node in a JMESPath AST and returns the evaluated result.
     *
     * @param array $node JMESPath AST node
     * @param mixed $data Data to evaluate
     *
     * @return mixed
     */
    public function visit(array $node, $data)
    {
        $this->root = $data;
        return $this->dispatch($node, $data);
    }

    /**
     * Recursively traverses an AST using depth-first, pre-order traversal.
     * The evaluation logic for each node type is embedded into a large switch
     * statement to avoid the cost of "double dispatch".
     * @return mixed
     */
    private function dispatch(array $node, $value)
    {
        $dispatcher = $this->fnDispatcher;

        $nodeValue = isset($node['value']) ? $node['value'] : null;
        $nodeType = $node['type'];

        switch ($nodeType) {
            case 'root':
                return $this->root;

            case 'field':
                if (is_array($value) || $value instanceof \ArrayAccess) {
                    return isset($value[$nodeValue]) ? $value[$nodeValue] : null;
                } elseif (is_object($value)) {
                    return isset($value->{$nodeValue}) ? $value->{$nodeValue} : null;
                }
                return null;
            case 'subexpression':
                $subExprResultValue = $this->dispatch($node['children'][0], $value);
                return $this->dispatch($node['children'][1], $subExprResultValue);

            case 'index':
                $nodeValue = $this->dispatch($node['children'][0], $value);

                if (is_array($value) || $value instanceof \ArrayAccess) {
                    $nodeValue = $nodeValue >= 0
                        ? $nodeValue
                        : $nodeValue + count($value);
                    return isset($value[$nodeValue]) ? $value[$nodeValue] : null;
                } elseif (is_object($value)) {
                    return isset($value->{$nodeValue}) ? $value->{$nodeValue} : null;
                }
                return null;

            case 'projection':
                $left = $this->dispatch($node['children'][0], $value);
                switch ($node['from']) {
                    case 'object':
                        if (!Utils::isObject($left)) {
                            return null;
                        }
                        break;
                    case 'array':
                        if (!Utils::isArray($left)) {
                            return null;
                        }
                        break;
                    default:
                        if (!is_array($left) || !($left instanceof \stdClass)) {
                            return null;
                        }
                }

                $collected = [];
                foreach ((array) $left as $val) {
                    $result = $this->dispatch($node['children'][1], $val);
                    if ($result !== null) {
                        $collected[] = $result;
                    }
                }

                return $collected;

            case 'flatten':
                static $skipElement = [];
                $value = $this->dispatch($node['children'][0], $value);

                if (!Utils::isArray($value)) {
                    return null;
                }

                $merged = [];
                foreach ($value as $values) {
                    // Only merge up arrays lists and not hashes
                    if (is_array($values) && isset($values[0])) {
                        $merged = array_merge($merged, $values);
                    } elseif ($values !== $skipElement) {
                        $merged[] = $values;
                    }
                }

                return $merged;

            case 'literal':
                return $nodeValue;
                
            case 'number':
                return $nodeValue;

            case 'current':
                return $value;
            
            case 'or':
                $result = $this->dispatch($node['children'][0], $value);
                return Utils::isTruthy($result)
                    ? $result
                    : $this->dispatch($node['children'][1], $value);

            case 'and':
                $result = $this->dispatch($node['children'][0], $value);
                return Utils::isTruthy($result)
                    ? $this->dispatch($node['children'][1], $value)
                    : $result;

            case 'not':
                return !Utils::isTruthy(
                    $this->dispatch($node['children'][0], $value)
                );

            case 'pipe':
                return $this->dispatch(
                    $node['children'][1],
                    $this->dispatch($node['children'][0], $value)
                );

            case 'multi_select_list':
                if ($value === null) {
                    return null;
                }

                $collected = [];
                foreach ($node['children'] as $node) {
                    $collected[] = $this->dispatch($node, $value);
                }

                return $collected;

            case 'multi_select_hash':
                if ($value === null) {
                    return null;
                }

                $collected = [];
                foreach ($node['children'] as $node) {
                    $nodeValue = isset($node['value']) ? $node['value'] : null;

                    $collected[$nodeValue] = $this->dispatch(
                        $node['children'][0],
                        $value
                    );
                }

                return $collected;

            case 'comparator':
                $left = $this->dispatch($node['children'][0], $value);
                $right = $this->dispatch($node['children'][1], $value);
                if ($nodeValue == '==') {
                    return Utils::isEqual($left, $right);
                } elseif ($nodeValue == '!=') {
                    return !Utils::isEqual($left, $right);
                } else {
                    return self::relativeCmp($left, $right, $nodeValue);
                }            

            case 'arithmetic_multiply_or_divide_or_mod':
                $left = $this->dispatch($node['children'][0], $value);
                $right = $this->dispatch($node['children'][1], $value);

                if ($nodeValue == '*') {
                    return $left * $right;
                } elseif ($nodeValue == '/') {
                    return $left / $right;
                } elseif ($nodeValue == '%') {
                    return $left % $right;
                }
                return 0;

            case 'arithmetic_plus_or_minus':
                $left = $this->dispatch($node['children'][0], $value);
                $right = $this->dispatch($node['children'][1], $value);

                if ($nodeValue == '+') {
                    return $left + $right;
                } elseif ($nodeValue == '-') {
                    return $left - $right;
                }
                return 0;

            case 'condition':
                return Utils::isTruthy($this->dispatch($node['children'][0], $value))
                    ? $this->dispatch($node['children'][1], $value)
                    : null;

            case 'function':
                $args = [];
                foreach ($node['children'] as $arg) {
                    $args[] = $this->dispatch($arg, $value);
                }
                return $dispatcher($nodeValue, $args);

            case 'slice':
                $from = isset($node['children'][0]) ? $this->dispatch($node['children'][0], $value) : null;
                $to = isset($node['children'][1]) ? $this->dispatch($node['children'][1], $value) : null;

                $step = isset($node['children'][2]) ? $this->dispatch($node['children'][2], $value) : 1;

                return is_string($value) || Utils::isArray($value)
                    ? Utils::slice(
                        $value,
                        $from,
                        $to,
                        $step
                    ) : null;

            case 'expref':
                $apply = $node['children'][0];
                return function ($value) use ($apply) {
                    return $this->visit($apply, $value);
                };

            default:
                throw new \RuntimeException("Unknown node type: {$node['type']}");
        }
    }

    /**
     * @return bool
     */
    private static function relativeCmp($left, $right, $cmp)
    {
        if (
            !(is_int($left) || is_float($left) || is_string($left)) ||
            !(is_int($right) || is_float($right) || is_string($right))
        ) {
            return false;
        }

        switch ($cmp) {
            case '>': return $left > $right;
            case '>=': return $left >= $right;
            case '<': return $left < $right;
            case '<=': return $left <= $right;
            default: throw new \RuntimeException("Invalid comparison: $cmp");
        }
    }
}
