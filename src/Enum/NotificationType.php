<?php

namespace App\Enum;

/**
 * What a notification is about: the first three go to the staff's bell in the CRM,
 * the rest to the client's personal account on the website.
 */
enum NotificationType: string
{
    case ClientRegistered = 'client_registered';
    case ClientEmailVerified = 'client_email_verified';
    case LeadReceived = 'lead_received';

    case Welcome = 'welcome';
    case LeadAccepted = 'lead_accepted';
    case LeadStatusChanged = 'lead_status_changed';
    case DocumentsAdded = 'documents_added';
    case PhotoReportAdded = 'photo_report_added';

    /** Icon of the CRM's bell (admin/_icons.html.twig) */
    public function icon(): string
    {
        return match ($this) {
            self::ClientRegistered, self::ClientEmailVerified, self::Welcome => 'client',
            self::LeadReceived, self::LeadAccepted, self::LeadStatusChanged => 'inbox',
            self::DocumentsAdded, self::PhotoReportAdded => 'document',
        };
    }
}
