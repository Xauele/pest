/*
 * Experimental Zend Engine function-entry observer for Pest TIA.
 * Positive evidence ONLY. This does not certify safe test exclusion.
 */
#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "zend_observer.h"
#include "ext/standard/info.h"
#include "zend_virtual_cwd.h"
#include <string.h>
#include <sys/stat.h>

#define PHP_PEST_OBSERVER_VERSION "0.1.0"

ZEND_BEGIN_MODULE_GLOBALS(pest_observer)
    zend_bool recording;
    zend_bool saw_dynamic_code;
    zend_bool untracked_effect_risk;
    zend_string *root;
    zend_string *last_filename;
    HashTable files;
ZEND_END_MODULE_GLOBALS(pest_observer)

ZEND_DECLARE_MODULE_GLOBALS(pest_observer)

#define PEST_OBSERVER_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(pest_observer, v)

static void php_pest_observer_init_globals(zend_pest_observer_globals *g)
{
    g->recording = 0;
    g->saw_dynamic_code = 0;
    g->untracked_effect_risk = 0;
    g->root = NULL;
    g->last_filename = NULL;
}

static zend_always_inline bool pest_observer_under_root(zend_string *file)
{
    zend_string *root = PEST_OBSERVER_G(root);
    size_t len;

    if (root == NULL) {
        return false;
    }

    len = ZSTR_LEN(root);
    if (len == 1 && ZSTR_VAL(root)[0] == '/') {
        return ZSTR_LEN(file) > 1 && ZSTR_VAL(file)[0] == '/';
    }

    return ZSTR_LEN(file) > len
        && ZSTR_VAL(file)[len] == '/'
        && memcmp(ZSTR_VAL(file), ZSTR_VAL(root), len) == 0;
}

static void pest_observer_on_begin(zend_execute_data *execute_data)
{
    zend_string *filename;

    if (!PEST_OBSERVER_G(recording) || execute_data->func == NULL
        || !ZEND_USER_CODE(execute_data->func->type)) {
        return;
    }

    filename = execute_data->func->op_array.filename;
    if (filename == NULL) {
        PEST_OBSERVER_G(saw_dynamic_code) = 1;
        return;
    }

    /*
     * Most application hot paths repeatedly enter functions from the same
     * file. Keep a refcounted last-file key so repeated calls pay no hash
     * table lookup or duplicate string ownership cost.
     */
    if (PEST_OBSERVER_G(last_filename) != NULL
        && zend_string_equals(filename, PEST_OBSERVER_G(last_filename))) {
        return;
    }

    if (PEST_OBSERVER_G(last_filename) != NULL) {
        zend_string_release(PEST_OBSERVER_G(last_filename));
    }
    PEST_OBSERVER_G(last_filename) = zend_string_copy(filename);

    if (strstr(ZSTR_VAL(filename), "eval()'d code") != NULL) {
        PEST_OBSERVER_G(saw_dynamic_code) = 1;
        return;
    }

    if (pest_observer_under_root(filename)) {
        zend_hash_add_empty_element(&PEST_OBSERVER_G(files), filename);
    }
}

static void pest_observer_on_sensitive_internal_begin(zend_execute_data *execute_data)
{
    (void) execute_data;

    if (PEST_OBSERVER_G(recording)) {
        PEST_OBSERVER_G(untracked_effect_risk) = 1;
    }
}

static zend_observer_fcall_handlers pest_observer_init(zend_execute_data *execute_data)
{
    zend_function *function = execute_data->func;

    if (function == NULL) {
        return (zend_observer_fcall_handlers){NULL, NULL};
    }

    if (ZEND_USER_CODE(function->type)) {
        return (zend_observer_fcall_handlers){pest_observer_on_begin, NULL};
    }

    /*
     * These are only illustrative native builtins that can read input outside
     * the PHP source graph. A hit is a risk flag, NOT a complete I/O inventory.
     */
    if (function->type == ZEND_INTERNAL_FUNCTION && function->common.function_name != NULL) {
        zend_string *name = function->common.function_name;

        if (zend_string_equals_literal_ci(name, "file_get_contents")
            || zend_string_equals_literal_ci(name, "fopen")
            || zend_string_equals_literal_ci(name, "fread")
            || zend_string_equals_literal_ci(name, "file")
            || zend_string_equals_literal_ci(name, "readfile")
            || zend_string_equals_literal_ci(name, "file_put_contents")
            || zend_string_equals_literal_ci(name, "getenv")
            || zend_string_equals_literal_ci(name, "curl_exec")) {
            return (zend_observer_fcall_handlers){pest_observer_on_sensitive_internal_begin, NULL};
        }
    }

    return (zend_observer_fcall_handlers){NULL, NULL};
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_pest_observer_start, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, canonicalProjectRoot, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_pest_observer_stop, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(pest_observer_start)
{
    zend_string *root;
    size_t len;
    char resolved[MAXPATHLEN];
    struct stat mode;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(root)
    ZEND_PARSE_PARAMETERS_END();

    if (PEST_OBSERVER_G(recording)) {
        RETURN_FALSE;
    }
    if (ZSTR_LEN(root) == 0 || ZSTR_VAL(root)[0] != '/') {
        RETURN_FALSE;
    }

    len = ZSTR_LEN(root);
    while (len > 1 && ZSTR_VAL(root)[len - 1] == '/') {
        len--;
    }

    /*
     * A lexically absolute path is not necessarily a canonical directory.
     * Fail rather than silently producing an incomplete dependency inventory.
     */
    if (VCWD_REALPATH(ZSTR_VAL(root), resolved) == NULL
        || strlen(resolved) != len
        || memcmp(resolved, ZSTR_VAL(root), len) != 0
        || VCWD_STAT(ZSTR_VAL(root), &mode) != 0
        || !S_ISDIR(mode.st_mode)) {
        RETURN_FALSE;
    }

    if (PEST_OBSERVER_G(root) != NULL) {
        zend_string_release(PEST_OBSERVER_G(root));
    }
    PEST_OBSERVER_G(root) = zend_string_init(ZSTR_VAL(root), len, 0);
    if (PEST_OBSERVER_G(last_filename) != NULL) {
        zend_string_release(PEST_OBSERVER_G(last_filename));
        PEST_OBSERVER_G(last_filename) = NULL;
    }
    zend_hash_clean(&PEST_OBSERVER_G(files));
    PEST_OBSERVER_G(saw_dynamic_code) = 0;
    PEST_OBSERVER_G(untracked_effect_risk) = 0;
    PEST_OBSERVER_G(recording) = 1;
    RETURN_TRUE;
}

PHP_FUNCTION(pest_observer_stop)
{
    zend_string *filename;
    zval files;

    ZEND_PARSE_PARAMETERS_NONE();

    if (!PEST_OBSERVER_G(recording)) {
        zend_throw_error(NULL, "pest_observer_stop() requires an active recording scope");
        RETURN_THROWS();
    }

    PEST_OBSERVER_G(recording) = 0;
    array_init(return_value);
    array_init_size(&files, zend_hash_num_elements(&PEST_OBSERVER_G(files)));

    ZEND_HASH_FOREACH_STR_KEY(&PEST_OBSERVER_G(files), filename) {
        if (filename != NULL) {
            add_next_index_str(&files, zend_string_copy(filename));
        }
    } ZEND_HASH_FOREACH_END();

    add_assoc_zval(return_value, "files", &files);
    add_assoc_bool(return_value, "dynamic_code", PEST_OBSERVER_G(saw_dynamic_code));
    add_assoc_bool(return_value, "untracked_effect_risk", PEST_OBSERVER_G(untracked_effect_risk));
    add_assoc_bool(return_value, "safe_to_skip_tests", 0);
    add_assoc_string(return_value, "scope", "function-entry-positive-evidence");

    zend_hash_clean(&PEST_OBSERVER_G(files));
    if (PEST_OBSERVER_G(last_filename) != NULL) {
        zend_string_release(PEST_OBSERVER_G(last_filename));
        PEST_OBSERVER_G(last_filename) = NULL;
    }
    PEST_OBSERVER_G(saw_dynamic_code) = 0;
    PEST_OBSERVER_G(untracked_effect_risk) = 0;
}

static const zend_function_entry pest_observer_functions[] = {
    PHP_FE(pest_observer_start, arginfo_pest_observer_start)
    PHP_FE(pest_observer_stop, arginfo_pest_observer_stop)
    PHP_FE_END
};

PHP_MINIT_FUNCTION(pest_observer)
{
    ZEND_INIT_MODULE_GLOBALS(pest_observer, php_pest_observer_init_globals, NULL);
    zend_observer_fcall_register(pest_observer_init);
    return SUCCESS;
}

PHP_RINIT_FUNCTION(pest_observer)
{
#if defined(ZTS) && defined(COMPILE_DL_PEST_OBSERVER)
    ZEND_TSRMLS_CACHE_UPDATE();
#endif
    PEST_OBSERVER_G(recording) = 0;
    PEST_OBSERVER_G(saw_dynamic_code) = 0;
    PEST_OBSERVER_G(untracked_effect_risk) = 0;
    PEST_OBSERVER_G(root) = NULL;
    PEST_OBSERVER_G(last_filename) = NULL;
    zend_hash_init(&PEST_OBSERVER_G(files), 16, NULL, NULL, 0);
    return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION(pest_observer)
{
    PEST_OBSERVER_G(recording) = 0;
    zend_hash_destroy(&PEST_OBSERVER_G(files));

    if (PEST_OBSERVER_G(last_filename) != NULL) {
        zend_string_release(PEST_OBSERVER_G(last_filename));
        PEST_OBSERVER_G(last_filename) = NULL;
    }

    if (PEST_OBSERVER_G(root) != NULL) {
        zend_string_release(PEST_OBSERVER_G(root));
        PEST_OBSERVER_G(root) = NULL;
    }
    return SUCCESS;
}

PHP_MINFO_FUNCTION(pest_observer)
{
    php_info_print_table_start();
    php_info_print_table_header(2, "pest_observer", "experimental; positive evidence only");
    php_info_print_table_row(2, "version", PHP_PEST_OBSERVER_VERSION);
    php_info_print_table_end();
}

zend_module_entry pest_observer_module_entry = {
    STANDARD_MODULE_HEADER,
    "pest_observer",
    pest_observer_functions,
    PHP_MINIT(pest_observer),
    NULL,
    PHP_RINIT(pest_observer),
    PHP_RSHUTDOWN(pest_observer),
    PHP_MINFO(pest_observer),
    PHP_PEST_OBSERVER_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_PEST_OBSERVER
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE(pest_observer)
#endif
