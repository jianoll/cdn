<?php namespace Vinelab\Cdn\Providers;

/**
 * @author Mahmoud Zalt <mahmoud@vinelab.com>
 */

use Vinelab\Cdn\Exceptions\MissingConfigurationException;
use Vinelab\Cdn\Providers\Contracts\ProviderInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Guzzle\Batch\BatchBuilder;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

/**
 * Class AwsS3Provider
 * @package Vinelab\Cdn\Provider
 */
class AwsS3Provider extends Provider implements ProviderInterface{

    protected $default = [
        'protocol' => 'https',
        'domain' => null,
        'threshold' => 10,
        'providers' => [
            'aws' => [
                's3' => [
                    'credentials' => [
                        'key'       => null,
                        'secret'    => null,
                    ],
                    'buckets' => null,
                    'acl' => 'public-read',
                ]
            ]
        ],
    ];

    /**
     * @var string
     */
    protected $domain;

    /**
     * @var string
     */
    protected $protocol;

    /**
     * @var string
     */
    protected $url;

    /**
     * @var string
     */
    protected $key;

    /**
     * @var string
     */
    protected $secret;

    /**
     * the type of permission on the files on the CDN
     *
     * @var string
     */
    protected $acl;

    /**
     * @var integer
     */
    protected $threshold;

    /**
     * @var array
     */
    protected $buckets;
    /**
     * @var boolean
     */
    protected $multiple_buckets;

    /**
     * @var Instance of Aws\S3\S3Client
     */
    protected $s3_client;

    /**
     * @var Instance of Guzzle\Batch\BatchBuilder
     */
    protected $batch;

    /**
     * @param \Symfony\Component\Console\Output\ConsoleOutput $console
     */
    public function __construct(ConsoleOutput $console)
    {
        $this->console = $console;
    }

    /**
     * Assign configurations to the properties and return itself
     *
     * @param $configurations
     *
     * @return $this
     */
    public function init($configurations)
    {
        $supplier = $this->parse($configurations);

        $this->domain       = $supplier['domain'];
        $this->protocol     = $supplier['protocol'];
        $this->url          = $supplier['url'];
        $this->key          = $supplier['key'];
        $this->secret       = $supplier['secret'];
        $this->acl          = $supplier['acl'];
        $this->threshold    = $supplier['threshold'];
        $this->buckets      = $supplier['buckets'];

        return $this;
    }

    /**
     * Read the configuration and prepare an array with the relevant configurations
     * for the (AWS S3) provider.
     *
     * @param $configurations
     *
     * @throws MissingConfigurationException
     * @return array
     */
    private function parse($configurations)
    {
        // merge the received config array with the default configurations array to
        // fill missed keys with null or default values.
        $this->default = array_merge($this->default, $configurations);

        // search for any null or empty field to throw an exception
        $missing = '';
        foreach ($this->default as $key => $value) {
            // Fix: needs to check for the sub arrays also
            if (empty($value) || $value == null || $value == '') {
                $missing .= $key;
            }
        }

        if ($missing)
            throw new MissingConfigurationException("Missing Configurations:" . $missing);

        // TODO: to be removed to a function of common configurations between call providers
        $threshold  = $this->default['threshold'];
        $protocol   = $this->default['protocol'];
        $domain     = $this->default['domain'];

        // aws s3 specific configurations
        $key        = $this->default['providers']['aws']['s3']['credentials']['key'];
        $secret     = $this->default['providers']['aws']['s3']['credentials']['secret'];
        $buckets    = $this->default['providers']['aws']['s3']['buckets'];
        $acl        = $this->default['providers']['aws']['s3']['acl'];

        $supplier = [
            'domain'    => $domain,
            'protocol'  => $protocol,
            'url'       => $protocol . '://' . $domain,  // compose the url from the protocol and the domain
            'key'       => $key,
            'secret'    => $secret,
            'acl'       => $acl,
            'threshold' => $threshold,
            'buckets'   => $buckets,
        ];

        return $supplier;
    }

    /**
     * Create a cdn instance and create a batch builder instance
     */
    private function connect()
    {
        // Instantiate an S3 client
        $this->s3_client = S3Client::factory( array(
                    'key'       => $this->key,
                    'secret'    => $this->secret,
                )
            );

        // Initialize the batch builder
        $this->batch = BatchBuilder::factory()
            ->transferCommands($this->threshold)
            ->autoFlushAt($this->threshold)
            ->build();
    }

    /**
     * Upload assets
     */
    public function upload($assets)
    {
        // connect before uploading
        $this->connect();

        // user terminal message
        $this->console->writeln('<fg=yellow>Uploading in progress...</fg=yellow>');

        // upload each asset file to the CDN
        foreach ($assets as $file) {

            try {
                $this->batch->add($this->s3_client->getCommand('PutObject', [

                    'Bucket'    => key($this->buckets), // the bucket name
                    'Key'       => $file->GetPathName(), // the path of the file on the server (CDN)
                    'Body'      => fopen($file->getRealpath(), 'r'), // the path of the path locally
                    'ACL'       => $this->acl, // the permission of the file

                ]));
            } catch (S3Exception $e) {
                echo "There was an error uploading this file ($file->getRealpath()).\n";
            }

        }

        // Execute batch.
        $commands = $this->batch->flush();

        // Fix: in small threshold output is not available (batch related thing)
        foreach ($commands as $command) {
            $result = $command->getResult();
            // user terminal message
            $this->console->writeln('<fg=magenta>URL: ' . $result->get('ObjectURL') . '</fg=magenta>');
        }

        // user terminal message
        $this->console->writeln('<fg=green>Upload completed successfully.</fg=green>');
    }

    /**
     * This function will be called from the CdnFacade class when
     * someone use this {{ Cdn::asset('') }} facade helper
     *
     * @param $path
     *
     * @return string
     */
    public function urlGenerator($path)
    {
        return $this->getProtocol() . key($this->getBuckets()) . '.' . $this->getDomain() . $path;
    }

    /**
     * @return string
     */
    public function getDomain()
    {
        return rtrim($this->domain, "/") . '/';
    }

    /**
     * @return string
     */
    public function getProtocol()
    {
        // make sure every protocol is formatted correctly (xxx://)
        return rtrim(rtrim($this->protocol, "/"), ":") . '://';
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * @param string $url
     */
    public function setUrl($url)
    {
        $this->url = $url;
    }

    /**
     * @return array
     */
    public function getBuckets()
    {
        return $this->buckets;
    }

}
