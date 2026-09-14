<?php

echo json_encode(LspHelper::modulesContext(
    __LARAVEL_LSP_MODULES_ROOT__,
    __LARAVEL_LSP_MODULES_ENABLED__,
));
