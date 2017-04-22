# Customer encryption class

We can imagine that you want to use your own encryption class, it is simpel.

### Warning: make sure you add the `<ENC>` after your encrypted string.

1. Create an new class and implement Ambta\DoctrineEncryptBundle\Encryptors\EncryptorInterface.
2. Create a constructor with the parameter secret key `__construct($secretKey)`
3. Create a function called encrypt with parameter data `encrypt($data)`
4. Create a function called decrypt with parameter data `decrypt($data)`
5. Insert your own encryption/decryption methods in those functions.
6. Define the class in your configuration file

## Example

### MyRijndael192Encryptor.php

``` php
<?php

namespace YourBundle\Library\Encryptor;

use Ambta\DoctrineEncryptBundle\Encryptors\EncryptorInterface;

/**
 * Class for AES128 encryption.
 */
class MyAES128Encryptor implements EncryptorInterface
{
    const ENCRYPT_NAME = 'AES-128';
    const ENCRYPT_MODE = 'ECB';

    /**
     * @var string
     */
    private $secretKey;

    /**
     * @var string
     */
    private $encryptMethod;

    /**
     * @var string
     */
    private $initializationVector;

    /**
     * {@inheritdoc}
     */
    public function __construct($key)
    {
        $this->secretKey = md5($key);
        $this->encryptMethod = sprintf('%s-%s', self::ENCRYPT_NAME, self::ENCRYPT_MODE);
        $this->initializationVector = openssl_random_pseudo_bytes(
            openssl_cipher_iv_length($this->encryptMethod)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function encrypt($data)
    {
        if (is_string($data)) {
            return trim(base64_encode(openssl_encrypt(
                $data,
                $this->encryptMethod,
                $this->secretKey,
                0,
                $this->initializationVector
            ))).'<ENC>';
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function decrypt($data)
    {
        if (is_string($data)) {
            $data = str_replace('<ENC>', '', $data);

            return trim(openssl_decrypt(
                base64_decode($data),
                $this->encryptMethod,
                $this->secretKey,
                0,
                $this->initializationVector
            ));
        }

        return $data;
    }
}
```

### config.yaml

``` yaml
ambta_doctrine_encrypt:
    secret_key:           AB1CD2EF3GH4IJ5KL6MN7OP8QR9ST0UW # Your own random 256 bit key (32 characters)
    encryptor_class:      \YourBundle\Library\Encryptor\MyRijndael192Encryptor # your own encryption class
```

Now your encryption is used to encrypt and decrypt data in the database.

# Store the key in a file

If you want to store the key outside your application it is possible thanks to CompilerPass component. First you'll have to create your compiler.

``` php
<?php

namespace Foo\AcmeBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ChangeSecretKeyAESCompiler implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        $container->setParameter(
            'vmelnik_doctrine_encrypt.secret_key',
            file_get_contents('../keys/aes256_secret.key') // You can choose whatever you want, you can also get the path from a parameter from config.yml
        );
    }
}

```

Then you need to register your compiler in the bundle's definition


```php
<?php

namespace Foo\AcmeBundme;

use Foo\AcmeBundme\DependencyInjection\Compiler\ChangeSecretKeyAESCompiler;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class FooAcmeBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new ChangeSecretKeyAESCompiler());
    }
}

```

And that's it ! Now you rely on a file instead of a configuration value

#### [Back to the index](https://github.com/ambta/DoctrineEncryptBundle/blob/master/Resources/doc/index.md)
