<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        // Rulesets
        '@PER-CS2.0'                          => true,
        '@PHP82Migration'                     => true,

        // Strict types
        'declare_strict_types'                => true,

        // Imports
        'ordered_imports'                     => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'                   => true,
        'global_namespace_import'             => ['import_classes' => false, 'import_functions' => false],

        // Strings & casting
        'single_quote'                        => true,
        'cast_spaces'                         => ['space' => 'none'],
        'short_scalar_cast'                   => true,
        'modernize_types_casting'             => true,

        // Arrays
        'array_syntax'                        => ['syntax' => 'short'],
        'array_indentation'                   => true,
        'whitespace_after_comma_in_array'     => true,
        'trailing_comma_in_multiline'         => ['elements' => ['arrays', 'match', 'parameters']],

        // Operators & expressions
        'binary_operator_spaces'              => ['default' => 'single_space'],
        'concat_space'                        => ['spacing' => 'one'],
        'logical_operators'                   => true,
        'ternary_to_null_coalescing'          => true,
        'no_unneeded_control_parentheses'     => true,

        // Functions & methods
        'method_chaining_indentation'         => true,
        'combine_nested_dirname'              => true,
        'no_useless_return'                   => true,
        'no_mixed_echo_print'                 => ['use' => 'echo'],
        'native_function_casing'              => true,
        'native_function_type_declaration_casing' => true,

        // Constants & magic
        'dir_constant'                        => true,
        'magic_constant_casing'               => true,
        'magic_method_casing'                 => true,

        // Cleanup
        'combine_consecutive_issets'          => true,
        'combine_consecutive_unsets'          => true,
        'no_empty_comment'                    => true,
        'no_empty_phpdoc'                     => true,
        'no_empty_statement'                  => true,
        'no_extra_blank_lines'                => ['tokens' => ['extra', 'throw', 'use']],
        'blank_line_before_statement'         => ['statements' => ['return', 'throw', 'yield']],

        // Comments & phpdoc
        'single_line_comment_style'           => true,
        'multiline_comment_opening_closing'   => true,
        'align_multiline_comment'             => ['comment_type' => 'phpdocs_like'],
        'phpdoc_align'                        => ['align' => 'left'],
        'phpdoc_indent'                       => true,
        'phpdoc_scalar'                       => true,
        'phpdoc_trim'                         => true,
        'phpdoc_separation'                   => true,
        'no_superfluous_phpdoc_tags'          => ['remove_inheritdoc' => true],
        'no_blank_lines_after_phpdoc'         => true,
        'no_blank_lines_after_class_opening'  => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
