# Roadblocks Silverstripe module

This module creates a database log of sessions, requests and validation exceptions. Where possible these are attached to an authenticated user.
The gateway then uses a rules based infrustructure to identify requests that should  be flagged, and when sufficent, block the request.
There are two new modeladmin tabs, Sessions and Roadblocks.
These are reports for both of these, and a queued job to remove stale requests logs.

## Sessions

A session is set for all requests now. To avoid this being reset on login the $session_regenerate_id is set to false in the config yml.

## Logs

Logging is done for the session, request and validation exceptions.
Each request logs the ip address, useragent, request type (configurable in ModelAdmin), Verb (GET, POST etc).

## excluded urls

the config/urlignore.yml file contains a list of preg_match values to be excluded from reuest logs. This reduces noise from _resouce requests, and frees up the /dev/build from being blocked.

## Gateway

The module works by inserting a new gateway after the member authentication has finished.
- Step 1 is to log the request and session data.
- Step 2 is to parse this against any established rules. If there is a rule violation a new roadblock record is created, or an existing one is added to. The roadblock record records the score, a list of rule violations as 'Roadblock Exceptions', and a list of the rules that are in effect against this record.
- Step 3 (optional) is to notify by email if a new roadblock record is created, or a roadblock comes into effect (Score is greater than or equal to 100.00).
- Step 4 is to check if there is a current roadblock in effect for the request.

## Overriding the Roadblock

If upon examination a roadblock should be removed there is an Admin Override heckbox on the roadblock modelAdmin. This will permit the member, or session to ignore all rules. Only override if you have high trust that the member or session is legitimate.

## Expiring the Roadblock

Setting an expiry value in the config yaml will set an expiry date whenever a score exceeds 100.00. When the expiry is over, on the next request by the member / session that is blocked, 100.00 will be subtracted from the score.
If the score is still over 100 a new expiry date will be set.
To illistrate, suppose I have a rule that is set to 50.00 (cumulative) at the member level, and an expiry is set to 600 seconds.
- The member triggers the rule the first time, a notification is sent to the admin and a roadblock record is created which holds the exception, the rule violated etc, but the user proceeds as normal.
- The rule is triggered a second time, the request is blocked, a new notification is sent to the admin, and the roadbock record is updated. All further requests will be blocked for the member for 10 minutes (600 seconds).
- The user tries a couple more times, building the score up to 200.00 and adding more exceptions to the roadblock record.
- after 10 minutes the user tries again with a request that will not be flagged, this time the expiry is past, and 100.00 is subtracted from the score. The score is still 100.00 so a new expiry for a further 10 minutes is added. The request is blocked.
- after another 10 minutes the user tries again with the first request that will be flagged. This time the score is increased to 150.00 due to the triggered rule, but reduced to 50.00 due to the expiry being past. The request is not blocked and the member can proceed.

## Rules
Rules are created on a "And" basis, measures against a request, session and member, where a combination of the following meets all criteria a score is generated.
When the score exceeds 100.00 a 'Roadblock' will be inforced returning a 404 error.

- Level:- Member level will loop through all sessions attached to the member, as well as loging attempts.
- LoginAttemptsStatus:- Consider just Failed, Success, or any login attempt status.
- LoginAttemptsNumber:- How many login attempts to allow before flagging.
- LoginAttemptsStartOffest:- The time period in seconds to look back over the login attempts.
- TypeCount:- The number of requests to a request type, which is a group of url rules eg. /^admin\//
- TypeStartOffset:- The time period in seconds to look back over.
- Verb The request verb for the rule.
    - Any
    - POST
    - GET
    - DELETE
    - PUT
    - CONNECT
    - OPTIONS
    - TRACE
    - PATCH
    - HEAD
- VerbCount:- How many requests of this verb to allow before flagging.
- VerbStartOffset:- The time period in seconds to look back over.
- IPAddress:-
  - If 'Allowed' only allow IP Addresses attached to the 'Type' to be accepted.
  - If 'Denied' only flag these IP Addresses allowing all others.
- IPAddressNumber: the number of requests allowed before flagging.
- IPAddressOffset:- The time frame in seconds to look back over.
- ExcludeGroup:- Similar to IPAddress above but for the member's group.
- ExcludePermission:- Similar to IPAddress above but for the member's permission.
- Score:- how much score to attribute to this rule. Scores will bubble up to the overall total for the current user session.
 - A score of 100.00 will automatically block the request.
 - A score of 0 will create a roadblock record, and notification if set, but not add to the overall score.
- Cumulative:- If set to 'Yes' the score will keep accumulating each time the rule is violated. Otherwise it is only captured once. 
- Status:- Is the rule in effect?

## Customisation

The rules can be extended to include new rules.
There following extended methods are available in addition to the standard ones:
- updateCaptureRequestData, populate the request log with custom fields / data
- updateCaptureSessionData, populate the session log with custom fields / data
- updateEvaluateRoadblockData, populate the roadblock record with custom fields / data
- updateEvaluateRoadblockExceptionData, populate the roadblock exceptions with custom fields / data
- updateEvaluateMember, Run your own custom rules at the member level
- updateEvaluateSession, run your own custom rules at the session level

## License

See [License](LICENSE.md)

This module template defaults to using the "BSD-3-Clause" license. The BSD-3 license is one of the most
permissive open-source license and is used by most Silverstripe CMS module.

## Installation

```sh
composer require aSmithSummer/roadblock
```

## Example configuration

As we are setting a new session for un-authenticated members, to prevent new sessions being created when they log in you should set login_recording to true. This is not fool proof but a big improvement.

in your app's base _config add the following:

```yaml
Silverstripe\Security\Security:
  login_recording: true;
```

You can override the default config in the roadblock module in the usual way. the default config is:
```yaml
---
Name: roadblock
---
Roadblock\Model\Roadblock:
  expiry_interval: 0 (time in seconds to block someone for)
  email_from: test@example.com (for notifications)
  email_to: test@example.com (for notifications)
  email_notify_on_partial: false (do not notify if a new roadblock record is created)
  email_notify_on_blocked: false (do not notify if a roadblock record is enforced)
---
Name: request_log_job
---
Roadblock\Jobs\TruncateRequestLogJob:
  keep_log_period: 20160 (how long in seconds to keep request logs for)
  keep_log_repeat_interval: "+1 day" (how often to truncate request logs)
```
