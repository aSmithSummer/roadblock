# Roadblocks Silverstripe module

This module creates a database log of requests and session identifiers, and where possible attaches these to an authenticated user.
The gateway then uses a rules based infrustructure to identify requests that should  be flagged, and when sufficent, block the request.
There are three new modeladmin tabs, the logs and the roadblock rules, and the blocks generated by the rules.

## Logs

Logging is done for the session, and request, where each session could have multiple requests.
Each request logs the ip address, useragent, request type, and broad category of the request (admin url etc)

## Rules
Rules are created on a "And" basis, measures against a request, session and member, where a combination of the following meets all criteria a score is generated.

- Level' => "Enum('Member,Session','Session')",
- Age' => "Enum('Any,Under18,Over65','Any')",
- Country' => "Enum('Any,NZ,Overseas','Any')",
- LoginAttemptsStatus' => "Enum('Any,Failed,Success','Any')",
- LoginAttemptsNumber' => 'Int',
- LoginAttemptsStartOffest' => 'Int',
- Verb' => "Enum('Any,POST,GET,DELETE,PUT,CONNECT,OPTIONS,TRACE,PATCH,HEAD','Any')",
- IPCount' => 'Int',
- Network' => "Enum('Any,Internal,External','Any')",
- TrustedDevicesCount' => 'Int',

If the score exceeds 100, then a block is put in place. The block will be in effect for the member, and session used for the request.
The score can be cumulativ, ie each attempt adds to the score, or static, only counts once.

## Customisation

The rules can be extended to include new rules

## License

See [License](LICENSE.md)

This module template defaults to using the "BSD-3-Clause" license. The BSD-3 license is one of the most
permissive open-source license and is used by most Silverstripe CMS module.

To publish your module under a different license:

- update the [`license.md`](LICENSE.md) file
- update the `license' key in your [`composer.json`](composer.json).

You can use [choosealicense.com](https://choosealicense.com) to help you pick a suitable license for your project.

You do not need to keep this section in your README file - the `LICENSE.md` file is sufficient.

## Installation

```sh
composer require aSmithSummer/roadblock
```

## Documentation

- [Documentation readme](docs/en/README.md)

Add links into your `docs/<language>` folder here unless your module only requires minimal documentation
in that case, add here and remove the docs folder. You might use this as a quick table of content if you
mhave multiple documentation pages.

## Example configuration

If your module makes use of the config API in Silverstripe CMS it's a good idea to provide an example config
here that will get the module working out of the box and expose the user to the possible configuration options.
Though note that in many cases simply linking to the documentation is enough.

Provide a syntax-highlighted code examples where possible.

```yaml
Page:
  config_option: true
  another_config:
    - item1
    - item2
```
