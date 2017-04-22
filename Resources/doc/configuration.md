#Configuration Reference

There are 3 paramaters in the configuration of the Doctrine encryption bundle which are all optional.

* **secret_key** - The key used to encrypt the data (256 bit)
    * 32 character long string
    * Default: empty, the bundle will use your Symfony2 secret key.

* **encryptor** - The encryptor used to encrypt the data
    * Encryptor name, currently available: AES-192 and AES-256
    * Default: AES-256

* **encryptor_class** - Custom class for encrypting data
    * Encryptor class, [your own encryptor class](https://github.com/ambta/DoctrineEncryptBundle/blob/master/Resources/doc/custom_encryptor.md) will override encryptor paramater
    * Default: empty
    
## yaml

``` yaml
ambta_doctrine_encrypt:
    secret_key:           AB1CD2EF3GH4IJ5KL6MN7OP8QR9ST0UW # Your own random 256 bit key (32 characters)
    encryptor:            AES-256 # AES-256 or AES-192
    encryptor_class:      \Ambta\DoctrineEncryptBundle\Encryptors\AES256Encryptor # your own encryption class
```

### xml

``` xml 
<ambta_doctrine_encrypt:config>
        <!-- Your own random 256 bit key (32 characters) -->
        <ambta_doctrine_encrypt:secret_key>AB1CD2EF3GH4IJ5KL6MN7OP8QR9ST0UW</ambta_doctrine_encrypt:secret_key>
        <!-- AES-256 or AES-192 -->
        <ambta_doctrine_encrypt:encryptor>AES-256</ambta_doctrine_encrypt:encryptor>
        <!-- your own encryption class -->
        <ambta_doctrine_encrypt:encryptor_class>\Ambta\DoctrineEncryptBundle\Encryptors\AES256Encryptor</ambta_doctrine_encrypt:encryptor_class>
</ambta_doctrine_encrypt:config>
```

## Usage

Read how to use the database encryption bundle in your project.

#### [Usage](https://github.com/ambta/DoctrineEncryptBundle/blob/master/Resources/doc/usage.md)