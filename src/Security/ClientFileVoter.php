<?php

namespace App\Security;

use App\Entity\ClientDocument;
use App\Entity\PhotoReport;
use App\Entity\PhotoReportPhoto;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may see a client's document or photo report: staff (super manager and above) any,
 * a client only their own. Used by the CRM downloads now and by the website's personal account later.
 *
 * @extends Voter<string, ClientDocument|PhotoReport|PhotoReportPhoto>
 */
final class ClientFileVoter extends Voter
{
    public const VIEW = 'CLIENT_FILE_VIEW';

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW === $attribute && ($subject instanceof ClientDocument || $subject instanceof PhotoReport || $subject instanceof PhotoReportPhoto);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        if ($this->accessDecisionManager->decide($token, [User::ROLE_SUPER_MANAGER])) {
            return true;
        }

        $owner = $subject instanceof PhotoReportPhoto ? $subject->getReport()?->getClient() : $subject->getClient();

        return null !== $owner && $owner->getId() === $user->getId();
    }
}
