<?php
/**
 * As configurações básicas do WordPress
 *
 * O script de criação wp-config.php usa esse arquivo durante a instalação.
 * Você não precisa usar o site, você pode copiar este arquivo
 * para "wp-config.php" e preencher os valores.
 *
 * Este arquivo contém as seguintes configurações:
 *
 * * Configurações do banco de dados
 * * Chaves secretas
 * * Prefixo do banco de dados
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Configurações do banco de dados - Você pode pegar estas informações com o serviço de hospedagem ** //
/** O nome do banco de dados do WordPress */
define( 'DB_NAME', 'barba' );

/** Usuário do banco de dados MySQL */
define( 'DB_USER', 'root' );

/** Senha do banco de dados MySQL */
define( 'DB_PASSWORD', '' );

/** Nome do host do MySQL */
define( 'DB_HOST', 'localhost' );

/** Charset do banco de dados a ser usado na criação das tabelas. */
define( 'DB_CHARSET', 'utf8mb4' );

/** O tipo de Collate do banco de dados. Não altere isso se tiver dúvidas. */
define( 'DB_COLLATE', '' );

/**#@+
 * Chaves únicas de autenticação e salts.
 *
 * Altere cada chave para um frase única!
 * Você pode gerá-las
 * usando o {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org
 * secret-key service}
 * Você pode alterá-las a qualquer momento para invalidar quaisquer
 * cookies existentes. Isto irá forçar todos os
 * usuários a fazerem login novamente.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'jx( 4rJt_56(&i]8PF2hsWOUPP]}6`Um-;`4D!!vJn4Ipmts{DN9jT.pSo1|N}i@' );
define( 'SECURE_AUTH_KEY',  '9G&NCsP{AP]Zz[cWYOw=9?`*D4{>~!HbqNs%D2btmpp]y*h;XdguiL0N*SVd#-sj' );
define( 'LOGGED_IN_KEY',    'SY~G?yGDB`J_1L|GD%Lh)`SJzf8Z.QK^.6G3GTR]V^,PXB/H+E?CLtp_sI{Km_0s' );
define( 'NONCE_KEY',        '1+8I!SyP8GK3& U@K5Q{.DYVbl}QYp:FN`)2w>Ls8@7r/YVExm#1C;m:nrkj q&w' );
define( 'AUTH_SALT',        'XG[[8P%lDj.]GS.CXs.GwPPPSl88J8jnPc~s)v4?0y[8TA*.C,(@Q^oUQ_B8P:$X' );
define( 'SECURE_AUTH_SALT', 'R[ZchYD~VKw5I<`(f)VV@O(qp:{i1FfD!Q?J#sve*}P:)SfYD|[`xS=2x[-kZ4Vz' );
define( 'LOGGED_IN_SALT',   '75zGJ+VQIWJ^eW_AMM<9Joq|a~2eFj2c4a~zV<SaE8!sWsm!Sna76RwnT<z4g5;A' );
define( 'NONCE_SALT',       'XY#tQ(?K79~fN|Bqx=V&$E+3$$r% 8JKg#6z.&Sn0A1OfVF^V,SfB|=Z?z c7362' );

/**#@-*/

/**
 * Prefixo da tabela do banco de dados do WordPress.
 *
 * Você pode ter várias instalações em um único banco de dados se você der
 * um prefixo único para cada um. Somente números, letras e sublinhados!
 */
$table_prefix = 'wp_';

/**
 * Para desenvolvedores: Modo de debug do WordPress.
 *
 * Altere isto para true para ativar a exibição de avisos
 * durante o desenvolvimento. É altamente recomendável que os
 * desenvolvedores de plugins e temas usem o WP_DEBUG
 * em seus ambientes de desenvolvimento.
 *
 * Para informações sobre outras constantes que podem ser utilizadas
 * para depuração, visite o Codex.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
define( 'WP_DEBUG', false );

/* Adicione valores personalizados entre esta linha até "Isto é tudo". */



/* Isto é tudo, pode parar de editar! :) */

/** Caminho absoluto para o diretório WordPress. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Configura as variáveis e arquivos do WordPress. */
require_once ABSPATH . 'wp-settings.php';
