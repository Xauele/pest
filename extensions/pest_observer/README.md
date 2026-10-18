# Pest Zend observer: proof-of-concept C extension

This is genuine native C code using the Zend Engine function-call observer API.
It is not part of php-src, it is not automatically enabled in Pest, and
it is **not a sound reason to omit tests**.

Build with PHP 8.4/8.5 and matching phpize/php-config:

    cd extensions/pest_observer
    phpize
    ./configure --enable-pest-observer
    make -j2
    php -n -d extension=$(pwd)/modules/pest_observer.so tests/proof.php

API: pest_observer_start(realpath($root)) enables request-local capture,
pest_observer_stop() returns files, dynamic_code, safe_to_skip_tests and scope.
safe_to_skip_tests is ALWAYS false. The extension records only positive
observations of PHP function/include op-array entry; it cannot infer that
dependencies not seen by the observer do not exist.

Outstanding barriers to usable TIA: top-level side effects, code executing
in native extensions, database/SQL/config/environment dependencies, autoload
and class aliases, nested child processes, Fibers and async overlapping
contexts, path canonicalization, error/exception and worker lifecycle,
source drift, runtime-generated code, coverage/PCOV parity and performance.

The comparison with no-extension, idle extension and active capture is
intentionally separated from comparing with Xdebug/PCOV. They observe
different quantities. No invented x-fold Pest performance claim.

The tests are written as standalone scripts to prove the C extension can
compile and load independently of Pest or its Composer dependencies.
