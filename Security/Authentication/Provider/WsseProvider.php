<?php
/**
 * This file is part of the <Ws-se> project.
 *
 * @category   Ws-se
 * @package    Security
 * @subpackage Authentication
 * @author     Etienne de Longeaux <etienne.delongeaux@gmail.com>
 * @copyright  2015 PI-GROUPE
 * @license    http://opensource.org/licenses/gpl-license.php GNU Public License
 * @version    2.3
 * @link       http://opensource.org/licenses/gpl-license.php
 * @since      2015-02-16
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Sfynx\WsseBundle\Security\Authentication\Provider;

use Symfony\Component\Security\Core\Authentication\Provider\AuthenticationProviderInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\NonceExpiredException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Sfynx\WsseBundle\Security\Authentication\Token\WsseUserToken;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provider WSSE
 *
 * @category   Ws-se
 * @package    Security
 * @subpackage Authentication
 * @author     Etienne de Longeaux <etienne.delongeaux@gmail.com>
 * @copyright  2015 PI-GROUPE
 * @license    http://opensource.org/licenses/gpl-license.php GNU Public License
 * @version    2.3
 * @link       http://opensource.org/licenses/gpl-license.php
 * @since      2015-02-16
 */
class WsseProvider implements AuthenticationProviderInterface
{
    /**
     * @var ContainerInterface
     */
    protected $container;
    
    /**
     * @var UserProviderInterface
     */    
    private $userProvider;
    
    /**
     * @var string
     */     
    private $cacheDir;

    public function __construct(UserProviderInterface $userProvider, $cacheDir , ContainerInterface $container)
    {
        $this->container    = $container;
        $this->userProvider = $userProvider;
        $this->cacheDir     = $cacheDir;
    }

    public function authenticate(TokenInterface $token)
    {
        $user = $this->userProvider->loadUserByUsername($token->getUsername());
        if ($user 
                && $this->validateDigest($token->digest, $token->nonce, $token->created, $user->getPassword())
        ) {
            $authenticatedToken = new WsseUserToken($user->getRoles());
            $authenticatedToken->setUser($user);

            return $authenticatedToken;
        }

        throw new AuthenticationException('The WSSE authentication failed.');
    }

    /**
     * This function is specific to Wsse authentication and is only used to help this example
     *
     * For more information specific to the logic here, see
     * https://github.com/symfony/symfony-docs/pull/3134#issuecomment-27699129
     */
    protected function validateDigest($digest, $nonce, $created, $secret)
    {
        // we set Expire value
        $Expire_lifetime = (int) $this->container->getParameter("sfynx.wsse.security.nonce_lifetime");
        
        // Check created time is not in the future
        if (strtotime($created) > time()) {
            return false;
        }

        // Expire timestamp after 5 minutes
        if (time() - strtotime($created) > $Expire_lifetime) {
            return false;
        }

        // Validate that the nonce is *not* used in the last 5 minutes
        // if it has, this could be a replay attack
        if (file_exists($this->cacheDir.'/'.$nonce) 
                && file_get_contents($this->cacheDir.'/'.$nonce) + $Expire_lifetime > time()
        ) {
            throw new NonceExpiredException('Previously used nonce detected');
        }
        // If cache directory does not exist we create it
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
        file_put_contents($this->cacheDir.'/'.$nonce, time());

        // Validate Secret
        $expected = static::makeDigest($nonce, $created, $secret);

        return $digest === $expected;
    }

    public function supports(TokenInterface $token)
    {
        return $token instanceof WsseUserToken;
    }
    
    public static function makeTonce() 
    {
        $chars   = "123456789abcdefghijklmnopqrstuvwxyz";
        $random  = "" . microtime();
        $random .= mt_rand();
        $mi      = strlen($chars) - 1;
        for ($i = 0; $i < 10; $i++) {
            $random .= $chars[mt_rand(0, $mi)];
        }
        $nonce = md5($random);
        
        return $nonce;
    }
    
    public static function makeDigest($nonce, $created, $password)
    {
        return base64_encode(sha1(base64_decode($nonce).$created.$password, true));
    }    

    public static function makeToken($username, $password)
    {
        $nonce  = static::makeTonce();
        $ts     = date('c');
        $digest = static::makeDigest($nonce, $ts, $password);
        
        return sprintf('X-WSSE: UsernameToken Username="%s", PasswordDigest="%s", Nonce="%s", Created="%s"',
                       $username, $digest, $nonce, $ts);
    }   
}
