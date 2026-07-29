{{--
    No visible UI. The server says "a message you can read just arrived"; the
    browser decides whether to raise a notification, based on whether the tab is
    actually visible.

    wire:ignore so a re-render never tears the Alpine component down mid-flight.
--}}
<div wire:ignore
     x-data="messageNotifications()"
     @message-notification.window="notify($event.detail)"></div>
