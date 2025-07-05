<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->exclude([
        'vendor',
        'cache',
        'coverage',
    ])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        '@PHP83Migration' => true,
        
        // Array formatting
        'array_syntax' => ['syntax' => 'short'],
        'array_indentation' => true,
        'trim_array_spaces' => true,
        'no_trailing_comma_in_singleline' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        
        // Code formatting
        'binary_operator_spaces' => ['default' => 'single_space'],
        'blank_line_after_opening_tag' => true,
        'braces' => ['position_after_functions_and_oop_constructs' => 'next'],
        'cast_spaces' => true,
        'class_attributes_separation' => [
            'elements' => ['method' => 'one', 'property' => 'one']
        ],
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'function_typehint_space' => true,
        'include' => true,
        'linebreak_after_opening_tag' => true,
        'lowercase_cast' => true,
        'magic_constant_casing' => true,
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
            'keep_multiple_spaces_after_comma' => true,
        ],
        'native_function_casing' => true,
        'new_with_braces' => true,
        'no_blank_lines_after_class_opening' => true,
        'no_empty_statement' => true,
        'no_leading_import_slash' => true,
        'no_leading_namespace_whitespace' => true,
        'no_multiline_whitespace_around_double_arrow' => true,
        'no_short_bool_cast' => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'no_spaces_around_offset' => true,
        'no_trailing_comma_in_singleline' => true,
        'no_unneeded_control_parentheses' => true,
        'no_unused_imports' => true,
        'no_useless_return' => true,
        'no_whitespace_before_comma_in_array' => true,
        'no_whitespace_in_blank_line' => true,
        'normalize_index_brace' => true,
        'object_operator_without_whitespace' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'php_unit_fqcn_annotation' => true,
        'phpdoc_align' => true,
        'phpdoc_annotation_without_dot' => true,
        'phpdoc_indent' => true,
        'phpdoc_inline_tag_normalizer' => true,
        'phpdoc_no_access' => true,
        'phpdoc_no_alias_tag' => true,
        'phpdoc_no_empty_return' => true,
        'phpdoc_no_package' => true,
        'phpdoc_no_useless_inheritdoc' => true,
        'phpdoc_return_self_reference' => true,
        'phpdoc_scalar' => true,
        'phpdoc_separation' => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_summary' => true,
        'phpdoc_to_comment' => true,
        'phpdoc_trim' => true,
        'phpdoc_trim_consecutive_blank_line_separation' => true,
        'phpdoc_types' => true,
        'phpdoc_var_without_name' => true,
        'return_type_declaration' => true,
        'semicolon_after_instruction' => true,
        'short_scalar_cast' => true,
        'single_class_element_per_statement' => true,
        'single_line_comment_style' => true,
        'single_quote' => true,
        'space_after_semicolon' => ['remove_in_empty_for_expressions' => true],
        'standardize_not_equals' => true,
        'ternary_operator_spaces' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments']],
        'trim_array_spaces' => true,
        'unary_operator_spaces' => true,
        'visibility_required' => true,
        'whitespace_after_comma_in_array' => true,
        
        // Performance optimizations
        'no_unneeded_curly_braces' => true,
        'no_unneeded_final_method' => true,
        'combine_consecutive_issets' => true,
        'combine_consecutive_unsets' => true,
        'explicit_string_variable' => true,
        'implode_call' => true,
        'is_null' => true,
        'modernize_types_casting' => true,
        'no_alias_functions' => true,
        'no_homoglyph_names' => true,
        'no_php4_constructor' => true,
        'no_unreachable_default_argument_value' => true,
        'no_useless_sprintf' => true,
        'non_printable_character' => true,
        'php_unit_construct' => true,
        'pow_to_exponentiation' => true,
        'psr_autoloading' => true,
        'random_api_migration' => true,
        'self_accessor' => true,
        'set_type_to_cast' => true,
        'ternary_to_null_coalescing' => true,
        'void_return' => true,
        
        // Strict typing
        'strict_comparison' => true,
        'strict_param' => true,
        
        // Modern PHP features
        'list_syntax' => ['syntax' => 'short'],
        'nullable_type_declaration_for_default_null_value' => true,
        'phpdoc_types_order' => ['null_adjustment' => 'always_last'],
        'use_arrow_functions' => true,
        'class_definition' => ['single_line' => true],
        'final_internal_class' => true,
        'static_lambda' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');