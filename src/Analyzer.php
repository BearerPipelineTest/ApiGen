<?php declare(strict_types = 1);

namespace ApiGenX;

use ApiGenX\Analyzer\AnalyzeResult;
use ApiGenX\Analyzer\AnalyzeTask;
use ApiGenX\Analyzer\NodeVisitors\PhpDocResolver;
use ApiGenX\Info\ClassInfo;
use ApiGenX\Info\ClassLikeInfo;
use ApiGenX\Info\ConstantInfo;
use ApiGenX\Info\ErrorInfo;
use ApiGenX\Info\InterfaceInfo;
use ApiGenX\Info\MethodInfo;
use ApiGenX\Info\NameInfo;
use ApiGenX\Info\ParameterInfo;
use ApiGenX\Info\PropertyInfo;
use ApiGenX\Info\TraitInfo;
use Iterator;
use Nette\Utils\FileSystem;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
use PhpParser\NodeTraverserInterface;
use PhpParser\Parser;
use PHPStan\PhpDocParser\Ast\PhpDoc\InvalidTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use Symfony\Component\Console\Helper\ProgressBar;


final class Analyzer
{
	public function __construct(
		private Locator $locator,
		private Parser $parser,
		private NodeTraverserInterface $traverser,
	) {
	}


	/**
	 * @param string[] $files indexed by []
	 */
	public function analyze(ProgressBar $progressBar, array $files): AnalyzeResult
	{
		/** @var AnalyzeTask[] $tasks indexed by [path] */
		$tasks = [];

		/** @var ClassLikeInfo[] $found indexed by [classLikeName] */
		$found = [];

		/** @var ClassLikeInfo[] $found indexed by [classLikeName] */
		$missing = [];

		/** @var ErrorInfo[][] $errors indexed by [errorKind][] */
		$errors = [];

		$schedule = function (string $file, bool $isPrimary) use (&$tasks, $progressBar): void {
			$file = realpath($file);
			$tasks[$file] ??= new AnalyzeTask($file, $isPrimary);
			$progressBar->setMaxSteps(count($tasks));
		};

		foreach ($files as $file) {
			$schedule($file, isPrimary: true);
		}

		foreach ($tasks as &$task) {
			foreach ($this->processTask($task) as $info) {
				if ($info instanceof ClassLikeInfo) {
					foreach ($info->dependencies as $dependency) {
						if (!isset($found[$dependency->fullLower]) && !isset($missing[$dependency->fullLower])) {
							$missing[$dependency->fullLower] = $info;
							$file = $this->locator->locate($dependency);

							if ($file !== null) {
								$schedule($file, isPrimary: false);
							}
						}
					}

					unset($missing[$info->name->fullLower]);
					$found[$info->name->fullLower] = $info;

				} elseif ($info instanceof ErrorInfo) {
					$errors[$info->kind][] = $info;

				} else {
					throw new \LogicException();
				}
			}

			$progressBar->setMessage($task->sourceFile);
			$progressBar->advance();
		}

		foreach ($missing as $fullLower => $dependencyOf) {
			$dependency = $dependencyOf->dependencies[$fullLower];
			$errors[ErrorInfo::KIND_MISSING_SYMBOL][] = new ErrorInfo(ErrorInfo::KIND_MISSING_SYMBOL, "Missing {$dependency->full}\nreferences by {$dependencyOf->name->full}");

			$info = new ClassInfo($dependency); // TODO: mark as missing
			$info->primary = false;
			$found[$info->name->fullLower] = $info;
		}

		$result = new AnalyzeResult();
		$result->classLike = $found;
		$result->error = $errors;

		return $result;
	}


	/**
	 * @return ClassLikeInfo[]|ErrorInfo[]
	 */
	private function processTask(AnalyzeTask $task): array
	{
		try {
			$ast = $this->parser->parse(FileSystem::read($task->sourceFile));
			$ast = $this->traverser->traverse($ast);

		} catch (\PhpParser\Error $e) {
			return [new ErrorInfo(ErrorInfo::KIND_SYNTAX_ERROR, "Parse error in file {$task->sourceFile}:\n{$e->getMessage()}")];
		}

		return iterator_to_array($this->processNodes($task, $ast), false);
	}


	/**
	 * @param Node[] $nodes
	 */
	private function processNodes(AnalyzeTask $task, array $nodes): Iterator // TODO: move to astTraverser?
	{
		foreach ($nodes as $node) {
			if ($node instanceof Node\Stmt\Namespace_) {
				yield from $this->processNodes($task, $node->stmts);

			} elseif ($node instanceof Node\Stmt\ClassLike && $node->name !== null) {
				yield $this->processClassLike($task, $node); // TODO: functions, constants, class aliases

			} elseif ($node instanceof Node) {
				foreach ($node->getSubNodeNames() as $name) {
					$subNode = $node->$name;

					if (is_array($subNode)) {
						yield from $this->processNodes($task, $subNode);

					} elseif ($subNode instanceof Node) {
						yield from $this->processNodes($task, [$subNode]);
					}
				}
			}
		}
	}


	private function processClassLike(AnalyzeTask $task, Node\Stmt\ClassLike $node): ClassLikeInfo // TODO: handle trait usage
	{
		$name = $this->processName($node->namespacedName);

		if ($node instanceof Node\Stmt\Class_) {
			$info = new ClassInfo($name);
			$info->abstract = $node->isAbstract();
			$info->final = $node->isFinal();
			$info->extends = $node->extends ? $this->processName($node->extends) : null;
			$info->implements = $this->processNameList($node->implements);

			foreach ($node->getTraitUses() as $traitUse) {
				$info->uses += $this->processNameList($traitUse->traits);
			}

			$info->dependencies += $info->extends ? [$info->extends->fullLower => $info->extends] : [];
			$info->dependencies += $info->implements;
			$info->dependencies += $info->uses;

		} elseif ($node instanceof Node\Stmt\Interface_) {
			$info = new InterfaceInfo($name);
			$info->extends = $this->processNameList($node->extends);
			$info->dependencies += $info->extends;

		} elseif ($node instanceof Node\Stmt\Trait_) {
			$info = new TraitInfo($name);

		} else {
			throw new \LogicException();
		}

		$classDoc = $this->extractPhpDoc($node);
		$info->primary = $task->isPrimary;
		$info->description = $this->extractDescription($classDoc);
		$info->tags = $this->extractTags($classDoc);
		$info->file = $task->sourceFile;
		$info->startLine = $node->getStartLine();
		$info->endLine = $node->getEndLine();

		foreach ($node->stmts as $member) {
			$memberDoc = $this->extractPhpDoc($member);
			$description = $this->extractDescription($memberDoc);
			$tags = $this->extractTags($memberDoc);

			if ($member instanceof Node\Stmt\ClassConst) {
				foreach ($member->consts as $constant) {
					$memberInfo = new ConstantInfo($constant->name->name, $constant->value);

					$memberInfo->description = $description;
					$memberInfo->tags = $tags;

					$memberInfo->startLine = $member->getComments() ? $member->getComments()[0]->getStartLine() : $member->getStartLine();
					$memberInfo->endLine = $member->getEndLine();

					$memberInfo->public = $member->isPublic();
					$memberInfo->protected = $member->isProtected();
					$memberInfo->private = $member->isPrivate();

					$info->constants[$constant->name->name] = $memberInfo;
					$info->dependencies += $this->extractExprDependencies($constant->value);
				}

			} elseif ($member instanceof Node\Stmt\Property) {
				$varTag = isset($tags['var'][0]) && $tags['var'][0] instanceof VarTagValueNode ? $tags['var'][0] : null;

				foreach ($member->props as $property) {
					$memberInfo = new PropertyInfo($property->name->name);

					$memberInfo->description = $varTag ? $varTag->description : $description;
					$memberInfo->tags = $tags;

					$memberInfo->startLine = $member->getComments() ? $member->getComments()[0]->getStartLine() : $member->getStartLine();
					$memberInfo->endLine = $member->getEndLine();

					$memberInfo->public = $member->isPublic();
					$memberInfo->protected = $member->isProtected();
					$memberInfo->private = $member->isPrivate();
					$memberInfo->static = $member->isStatic();

					$memberInfo->type = $varTag ? $varTag->type : $this->processTypeOrNull($member->type);
					$memberInfo->default = $property->default;

					$info->properties[$property->name->name] = $memberInfo;
					$info->dependencies += $property->default ? $this->extractExprDependencies($property->default) : [];
					$info->dependencies += $memberInfo->type ? $this->extractTypeDependencies($memberInfo->type) : [];
				}

			} elseif ($member instanceof Node\Stmt\ClassMethod) {
				$returnTag = isset($tags['return'][0]) && $tags['return'][0] instanceof ReturnTagValueNode ? $tags['return'][0] : null;

				$memberInfo = new MethodInfo($member->name->name);

				$memberInfo->description = $description;
				$memberInfo->tags = $tags;

				$memberInfo->parameters = $this->processParameters($memberDoc->getParamTagValues(), $member->params);
				$memberInfo->returnType = $returnTag ? $returnTag->type : $this->processTypeOrNull($member->returnType);
				$memberInfo->byRef = $member->byRef;

				$memberInfo->startLine = $member->getComments() ? $member->getComments()[0]->getStartLine() : $member->getStartLine();
				$memberInfo->endLine = $member->getEndLine();

				$memberInfo->public = $member->isPublic();
				$memberInfo->protected = $member->isProtected();
				$memberInfo->private = $member->isPrivate();

				$memberInfo->static = $member->isStatic();
				$memberInfo->abstract = $member->isAbstract();
				$memberInfo->final = $member->isFinal();

				$info->methods[$memberInfo->nameLower] = $memberInfo;
				$info->dependencies += $memberInfo->returnType ? $this->extractTypeDependencies($memberInfo->returnType) : [];

				foreach ($memberInfo->parameters as $parameterInfo) {
					$info->dependencies += $parameterInfo->type ? $this->extractTypeDependencies($parameterInfo->type) : [];
					$info->dependencies += $parameterInfo->default ? $this->extractExprDependencies($parameterInfo->default) : [];
				}
			}
		}

		return $info;
	}


	/**
	 * @param  ParamTagValueNode[] $paramTags
	 * @param  Node\Param[]	       $parameters
	 * @return ParameterInfo[]
	 */
	private function processParameters(array $paramTags, array $parameters): array
	{
		$paramTags = array_column($paramTags, null, 'parameterName');

		$parameterInfos = [];
		foreach ($parameters as $parameter) {
			assert($parameter->var instanceof Node\Expr\Variable);
			assert(is_scalar($parameter->var->name));

			$paramTag = $paramTags["\${$parameter->var->name}"] ?? null;
			$parameterInfo = new ParameterInfo($parameter->var->name);
			$parameterInfo->description = $paramTag ? $paramTag->description : '';
			$parameterInfo->type = $paramTag ? $paramTag->type : $this->processTypeOrNull($parameter->type);
			$parameterInfo->byRef = $parameter->byRef;
			$parameterInfo->variadic = $parameter->variadic || ($paramTag && $paramTag->isVariadic);
			$parameterInfo->default = $parameter->default;

			$parameterInfos[$parameter->var->name] = $parameterInfo;
		}

		return $parameterInfos;
	}


	private function processName(Node\Name $name): NameInfo
	{
		return new NameInfo($name->toString()); // TODO: utilize already parsed structure?
	}


	/**
	 * @return NameInfo[] indexed by [classLikeName]
	 */
	private function processNameList(array $names): array
	{
		$nameMap = [];

		foreach ($names as $name) {
			$nameInfo = $this->processName($name);
			$nameMap[$nameInfo->fullLower] = $nameInfo;
		}

		return $nameMap;
	}


	/**
	 * @param null|Identifier|Name|NullableType|UnionType $node
	 */
	private function processTypeOrNull(?Node $node): ?TypeNode
	{
		return $node ? $this->processType($node) : null;
	}


	/**
	 * @param Identifier|Name|NullableType|UnionType $node
	 */
	private function processType(Node $node): TypeNode
	{
		if ($node instanceof NullableType) {
			return new NullableTypeNode($this->processType($node->type));
		}

		if ($node instanceof UnionType) {
			return new UnionTypeNode(array_map([$this, 'processType'], $node->types));
		}

		return new IdentifierTypeNode($node->toString());
	}


	private function extractPhpDoc(Node $node): PhpDocNode
	{
		return $node->getAttribute('phpDoc') ?? new PhpDocNode([]);
	}


	private function extractDescription(PhpDocNode $node): string
	{
		$lines = [];
		foreach ($node->children as $child) {
			if ($child instanceof PhpDocTextNode) {
				$lines[] = $child->text;

			} else {
				break;
			}
		}

		return trim(implode("\n", $lines));
	}


	/**
	 * @return PhpDocTagValueNode[][] indexed by [tagName][]
	 */
	private function extractTags(PhpDocNode $node): array
	{
		$tags = [];

		foreach ($node->getTags() as $tag) {
			if (!$tag->value instanceof InvalidTagValueNode) {
				$tags[substr($tag->name, 1)][] = $tag->value;
			}
		}

		return $tags;
	}


	private function extractExprDependencies(Node\Expr $value): array
	{
		return []; // TODO!
	}


	private function extractTypeDependencies(TypeNode $type): array
	{
		$dependencies = [];

		foreach (PhpDocResolver::getIdentifiers($type) as $identifier) {
			$lower = strtolower($identifier->name);
			if (!isset(PhpDocResolver::KEYWORDS[$lower])) {
				$dependencies[$lower] = new NameInfo($identifier->name);
			}
		}

		return $dependencies;
	}
}
