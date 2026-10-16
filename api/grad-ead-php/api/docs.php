<?php
/*
 * GET docs -- documentacao interativa (Swagger). Aberta: para testar as rotas,
 * clique em Authorize e cole o token.
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Risco de evasao -- Graduacao EAD</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css">
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
<script>
  // openapi.php relativo a esta pagina: funciona com e sem a URL sem extensao
  SwaggerUIBundle({url: "openapi.php", dom_id: "#swagger-ui", persistAuthorization: true});
</script>
</body>
</html>
