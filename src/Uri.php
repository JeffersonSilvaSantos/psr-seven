<?php

declare(strict_types=1);

namespace Sofac\Psr\Seven;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;
use Sofac\Standards\Rfc3986\Character;

class Uri implements UriInterface

{
    /** @var string */
    private const string HTTP_DEFAULT_HOST = 'localhost';

    /** @var array|int[] */
    private const array PORT_DEFAULT = ['http' => 80, 'https' => 443, 'ftp' => 21];

    /** @var string Uri scheme. */
    private string $scheme = '';

    /** @var string Uri user info. */
    private string $userInfo = '';

    /** @var string Uri host. */
    private string $host = '';

    /** @var int|null Uri port. */
    private ?int $port = null;

    /** @var string Uri path. */
    private string $path = '';

    /** @var string Uri query string. */
    private string $query = '';

    /** @var string Uri fragment. */
    private string $fragment = '';

    /**
     * PSR-7 URI implementation.
     *
     * @author Jefferson Silva
     */
    public function __construct(string $uri = '')
    {
        if ($uri !== '') :
            $analyzed = $this->parse($uri);
            if (empty($analyzed)) throw new InvalidArgumentException("URI '{$uri}' is not a valid URI");
            $this->buildUri($analyzed);
        endif;
    }

    /**
     * Distinguishes between an IPv6 URL and another type of URL.
     *
     * @param string $uri
     * @return string A percent-encoded URL.
     */
    private function parseUriOrIpv6(string $uri): string
    {
        preg_match("#^(.*://\[[0-9:a-fA-F]+])(.*?)$#", $uri, $match);
        if ($match) return $match[1] . $this->encodePercent($match[2]);
        return $this->encodePercent($uri);
    }

    /**
     * performs percent encoding.
     *
     * @param string $target
     * @param string|null $includeChar
     * @return string a string encoded in the percent encode pattern.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc3986#section-2.1
     */
    private function encodePercent(string $target, ?string $includeChar = null): string
    {
        $char = $includeChar !== null ? $includeChar : '';
        $pattern = "#[^"
            . Character::subDelimsForRegex("!", "$", "'", "(", ")", "*", "+", ",", ";")
            . Character::gemDelimsForRegex("[", "]") . $char ."%]+|" . Character::percentIdentifyRegex() . "#";
        return preg_replace_callback($pattern, fn($match) => \rawurlencode($match[0]), $target);
    }

    /**
     * Which internally used the native parse-url function
     *
     * @param string $uri provider.
     * @return array
     */
    private function parse(string $uri): array
    {

        $uri = $this->parseUriOrIpv6($uri);
        var_dump($uri);
        $parser = parse_url($this->parseUriOrIpv6($uri));

        return $parser ? array_map('rawurldecode', $parser) : [];
    }


    /**
     * It constructs the URI, performing the necessary validations.
     *
     * @param array $parse The parse function, which internally used the native parse-url function, has been removed.
     * @return void
     */
    private function buildUri(array $parse): void
    {
        foreach ($parse as $key => $value) :
            switch ($key) {
                case 'scheme':
                    $this->changeScheme($this, $value);
                    break;
                case 'host':
                    $this->changeHost($this, $value);
                    break;
                case 'port':
                    $this->changePort($this, $value);
                    break;
                case 'path':
                    $this->changePath($this, $value);
                    break;
                case 'query':
                    $this->changeQuery($this, $value);
                    break;
                case 'fragment':
                    $this->changeFragment($this, $value);
                    break;
                default;
            }
        endforeach;
    }

    /**
     * @param Uri $uri
     * @param string $scheme
     *
     * @return void
     * @throws InvalidArgumentException If the schema fails validation, see RFC 3986#section-3.1.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.1
     */
    private function changeScheme(Uri $uri, string $scheme): void
    {
        $scheme = $this->validateScheme($scheme);
        if ($scheme === '') :
            $uri->scheme = '';
            $uri->changePort($this, null);
            return;
        endif;

        $uri->scheme = $scheme;

        $uri->changePort($uri, $uri->port);
    }

    private function changeHost(Uri $uri, string $host): void
    {
        $host = $this->validateHost($host);
        $uri->host = $host;
    }

    /**
     * @param Uri $uri
     * @param string|int|null $port
     * @return void
     */
    private function changePort(Uri $uri, string|int|null $port): void
    {
        $port = $uri->validatePort($port);

        if ($port === null || $uri->scheme === '') :
            $uri->port = null;
            return;
        endif;

        if (key_exists($uri->scheme, self::PORT_DEFAULT) && !in_array($port, $uri::PORT_DEFAULT)) :

            $uri->port = $port;
            return;
        endif;

        if (!key_exists($uri->scheme, self::PORT_DEFAULT) && !in_array($port, $uri::PORT_DEFAULT)) :
            $uri->port = $port;
            return;
        endif;


        $uri->port = null;
    }

    private function changePath(Uri $uri, string $path): void
    {
        $uri->path = $this->validatePath($path);
    }

    private function validatePath(string $path): string
    {
        return $this->encodePercent($path);
    }

    private function changeQuery(Uri $uri, string $query): void
    {
        $uri->query = $this->validateQueryOrFragment($query);
    }

    private function validateQueryOrFragment(string $query): string
    {
        return $this->encodePercent($query);
    }

    private function changeFragment(Uri $uri, string $fragment): void
    {
        $uri->fragment = $this->validateQueryOrFragment($fragment);
    }

    /**
     * Validates a scheme following RFC 3986.
     *
     * @param string $scheme If an empty string is passed,
     * it is understood that the schema, if it exists, will be removed.
     * @return string A scheme normalized to lowercase
     *
     * @throws InvalidArgumentException If the schema fails validation, see RFC 3986#section-3.1.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.1
     */
    private function validateScheme(string $scheme): string
    {
        if (!preg_match("#^$|^[A-Za-z0-9]+[A-Za-z0-9+-.]*?$#", $scheme))
            throw new InvalidArgumentException("Scheme '{$scheme}' is not valid");
        return strtolower($scheme);
    }

    /**
     * Responsible for filtering and validating the host.
     *
     * @param string $host host obtained from the parse-url function.
     * @return string eating the host extracted from URI.
     *
     * @throws InvalidArgumentException if the host is not a string.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.2.2
     */
    private function validateHost(string $host): string
    {
        if (is_numeric($host)) throw new InvalidArgumentException("Host '{$host}' is not numeric");
        //validate ipv6 \[[0-9:a-fA-F]+])
        return strtolower($host);
    }

    private function validatePort(string|int|null $port): ?int
    {
        if ($port === null) {
            return null;
        }

        if (is_string($port)) :
            if (!is_numeric($port)) throw new InvalidArgumentException("Port '{$port}' is not numeric");
            $port = (int)$port;
        endif;

        if (0 > $port || 0xFFFF < $port) {
            throw new InvalidArgumentException(
                sprintf('Invalid port: %d. Must be between 0 and 65535', $port)
            );
        }

        return $port;
    }

    /**
     * Retrieve the scheme component of the URI.
     *
     * If no scheme is present, this method MUST return an empty string.
     *
     * The value returned MUST be normalized to lowercase, per RFC 3986
     * Section 3.1.
     *
     * The trailing ":" character is not part of the scheme and MUST NOT be
     * added.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.1
     * @return string The URI scheme.
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * Retrieve the authority component of the URI.
     *
     * If no authority information is present, this method MUST return an empty
     * string.
     *
     * The authority syntax of the URI is:
     *
     * <pre>
     * [user-info@]host[:port]
     * </pre>
     *
     * If the port component is not set or is the standard port for the current
     * scheme, it SHOULD NOT be included.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.2
     * @return string The URI authority, in "[user-info@]host[:port]" format.
     */
    public function getAuthority(): string
    {
        // TODO: Implement getAuthority() method.
    }

    /**
     * Retrieve the user information component of the URI.
     *
     * If no user information is present, this method MUST return an empty
     * string.
     *
     * If a user is present in the URI, this will return that value;
     * additionally, if the password is also present, it will be appended to the
     * user value, with a colon (":") separating the values.
     *
     * The trailing "@" character is not part of the user information and MUST
     * NOT be added.
     *
     * @return string The URI user information, in "username[:password]" format.
     */
    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    /**
     * Retrieve the host component of the URI.
     *
     * If no host is present, this method MUST return an empty string.
     *
     * The value returned MUST be normalized to lowercase, per RFC 3986
     * Section 3.2.2.
     *
     * @see http://tools.ietf.org/html/rfc3986#section-3.2.2
     * @return string The URI host.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Retrieve the port component of the URI.
     *
     * If a port is present, and it is non-standard for the current scheme,
     * this method MUST return it as an integer. If the port is the standard port
     * used with the current scheme, this method SHOULD return null.
     *
     * If no port is present, and no scheme is present, this method MUST return
     * a null value.
     *
     * If no port is present, but a scheme is present, this method MAY return
     * the standard port for that scheme, but SHOULD return null.
     *
     * @return null|int The URI port.
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * Retrieve the path component of the URI.
     *
     * The path can either be empty or absolute (starting with a slash) or
     * rootless (not starting with a slash). Implementations MUST support all
     * three syntaxes.
     *
     * Normally, the empty path "" and absolute path "/" are considered equal as
     * defined in RFC 7230 Section 2.7.3. But this method MUST NOT automatically
     * do this normalization because in contexts with a trimmed base path, e.g.
     * the front controller, this difference becomes significant. It's the task
     * of the user to handle both "" and "/".
     *
     * The value returned MUST be percent-encoded, but MUST NOT double-encode
     * any characters. To determine what characters to encode, please refer to
     * RFC 3986, Sections 2 and 3.3.
     *
     * As an example, if the value should include a slash ("/") not intended as
     * delimiter between path segments, that value MUST be passed in encoded
     * form (e.g., "%2F") to the instance.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-2
     * @see https://tools.ietf.org/html/rfc3986#section-3.3
     * @return string The URI path.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Retrieve the query string of the URI.
     *
     * If no query string is present, this method MUST return an empty string.
     *
     * The leading "?" character is not part of the query and MUST NOT be
     * added.
     *
     * The value returned MUST be percent-encoded, but MUST NOT double-encode
     * any characters. To determine what characters to encode, please refer to
     * RFC 3986, Sections 2 and 3.4.
     *
     * As an example, if a value in a key/value a pair of the query string should
     * include an ampersand ("&") not intended as a delimiter between values,
     * that value MUST be passed in encoded form (e.g., "%26") to the instance.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-2
     * @see https://tools.ietf.org/html/rfc3986#section-3.4
     * @return string The URI query string.
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Retrieve the fragment component of the URI.
     *
     * If no fragment is present, this method MUST return an empty string.
     *
     * The leading "#" character is not part of the fragment and MUST NOT be
     * added.
     *
     * The value returned MUST be percent-encoded, but MUST NOT double-encode
     * any characters. To determine what characters to encode, please refer to
     * RFC 3986, Sections 2 and 3.5.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-2
     * @see https://tools.ietf.org/html/rfc3986#section-3.5
     * @return string The URI fragment.
     */
    public function getFragment(): string
    {
        return $this->fragment;
    }

    /**
     * Return an instance with the specified scheme.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified scheme.
     *
     * Implementations MUST support the schemes "http" and "https" case
     * insensitively, and MAY accommodate other schemes if required.
     *
     * An empty scheme is equivalent to removing the scheme.
     *
     * @param string $scheme The scheme to use with the new instance.
     * @return static A new instance with the specified scheme.
     * @throws InvalidArgumentException for invalid or unsupported schemes.
     */
    public function withScheme(string $scheme): UriInterface
    {
        if ($scheme === $this->scheme) return $this;
        $clone = clone $this;
        $this->changeScheme($clone, $scheme);
        return $clone;
    }

    /**
     * Return an instance with the specified user information.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified user information.
     *
     * Password is optional, but the user information MUST include the
     * user; an empty string for the user is equivalent to removing user
     * information.
     *
     * @param string $user The user name to use for authority.
     * @param null|string $password The password associated with $user.
     * @return static A new instance with the specified user information.
     */
    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        // TODO: Implement withUserInfo() method.
    }

    /**
     * Return an instance with the specified host.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified host.
     *
     * An empty host value is equivalent to removing the host.
     *
     * @param string $host The hostname to use with the new instance.
     * @return static A new instance with the specified host.
     * @throws InvalidArgumentException for invalid hostnames.
     */
    public function withHost(string $host): UriInterface
    {
        // TODO: Implement withHost() method.
    }

    /**
     * Return an instance with the specified port.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified port.
     *
     * Implementations MUST raise an exception for ports outside the
     * established TCP and UDP port ranges.
     *
     * A null value provided for the port is equivalent to removing the port
     * information.
     *
     * @param null|int $port The port to use with the new instance; a null value
     *     removes the port information.
     * @return static A new instance with the specified port.
     * @throws InvalidArgumentException for invalid ports.
     */
    public function withPort(?int $port): UriInterface
    {
        if ($port === $this->port) return $this;
        $clone = clone $this;
        $this->changePort($clone, $port);
        return $clone;
    }

    /**
     * Return an instance with the specified path.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified path.
     *
     * The path can either be empty or absolute (starting with a slash) or
     * rootless (not starting with a slash). Implementations MUST support all
     * three syntaxes.
     *
     * If the path is intended to be domain-relative rather than path relative then
     * it must begin with a slash ("/"). Paths not starting with a slash ("/")
     * are assumed to be relative to some base path known to the application or
     * consumer.
     *
     * Users can provide both encoded and decoded path characters.
     * Implementations ensure the correct encoding as outlined in getPath().
     *
     * @param string $path The path to use with the new instance.
     * @return static A new instance with the specified path.
     * @throws InvalidArgumentException for invalid paths.
     */
    public function withPath(string $path): UriInterface
    {
        // TODO: Implement withPath() method.
    }

    /**
     * Return an instance with the specified query string.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified query string.
     *
     * Users can provide both encoded and decoded query characters.
     * Implementations ensure the correct encoding as outlined in getQuery().
     *
     * An empty query string value is equivalent to removing the query string.
     *
     * @param string $query The query string to use with the new instance.
     * @return static A new instance with the specified query string.
     * @throws InvalidArgumentException for invalid query strings.
     */
    public function withQuery(string $query): UriInterface
    {
        // TODO: Implement withQuery() method.
    }

    /**
     * Return an instance with the specified URI fragment.
     *
     * This method MUST retain the state of the current instance, and return
     * an instance that contains the specified URI fragment.
     *
     * Users can provide both encoded and decoded fragment characters.
     * Implementations ensure the correct encoding as outlined in getFragment().
     *
     * An empty fragment value is equivalent to removing the fragment.
     *
     * @param string $fragment The fragment to use with the new instance.
     * @return static A new instance with the specified fragment.
     */
    public function withFragment(string $fragment): UriInterface
    {
        // TODO: Implement withFragment() method.
    }

    /**
     * Return the string representation as a URI reference.
     *
     * Depending on which components of the URI are present, the resulting
     * string is either a full URI or relative reference according to RFC 3986,
     * Section 4.1. The method concatenates the various components of the URI,
     * using the appropriate delimiters:
     *
     * - If a scheme is present, it MUST be suffixed by ":".
     * - If an authority is present, it MUST be prefixed by "//".
     * - The path can be concatenated without delimiters. But there are two
     *   cases where the path has to be adjusted to make the URI reference
     *   valid as PHP does not allow to throw an exception in __toString():
     *     - If the path is rootless and an authority is present, the path MUST
     *       be prefixed by "/".
     *     - If the path is starting with more than one "/" and no authority is
     *       present, the starting slashes MUST be reduced to one.
     * - If a query is present, it MUST be prefixed by "?".
     * - If a fragment is present, it MUST be prefixed by "#".
     *
     * @see http://tools.ietf.org/html/rfc3986#section-4.1
     * @return string
     */
    public function __toString(): string
    {
        // TODO: Implement __toString() method.
    }
}