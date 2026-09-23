<?php

namespace App\Service\Api;

use App\Entity\ClientDocument;
use App\Entity\Lead;
use App\Entity\LeadItem;
use App\Entity\MediaPlan;
use App\Entity\MediaPlanItem;
use App\Entity\MediaPlanServiceLine;
use App\Entity\Payment;
use App\Entity\PhotoReport;
use App\Entity\PhotoReportPhoto;
use App\Entity\User;
use App\Enum\BookingStatus;
use App\Helpers\ProductHelper;
use Symfony\Component\Clock\ClockInterface;

/**
 * The client's own data for the website's personal account.
 * Only what the client may see: selling prices and statuses, never partner costs, margins or manager notes.
 */
class AccountPresenter
{
    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            // documents, plans and payments reach the account only after that
            'emailVerified' => $user->isEmailVerified(),
            'name' => $user->getName(),
            'phone' => $user->getPhone(),
            'clientType' => $user->getClientType()?->value,
            'clientTypeLabel' => $user->getClientType()?->label(),
            'title' => $user->getClientTitle(),
            'company' => $user->getCompany(),
            'inn' => $user->getInn(),
            'kpp' => $user->getKpp(),
            'ogrn' => $user->getOgrn(),
            'legalAddress' => $user->getLegalAddress(),
        ];
    }

    /**
     * A media plan as a campaign: its sides, money and where it stands.
     *
     * @return array<string, mixed>
     */
    public function campaign(MediaPlan $plan): array
    {
        [$status, $label] = $this->campaignStatus($plan);

        return [
            'id' => $plan->getId(),
            'title' => $plan->getTitle(),
            'from' => $plan->getStartMonth()?->format('Y-m-d'),
            'to' => $plan->getEndDate()?->format('Y-m-d'),
            'months' => $plan->getMonths(),
            'status' => $status,
            'statusLabel' => $label,
            'items' => array_map($this->campaignItem(...), $plan->getItems()->toArray()),
            'services' => array_map(static fn (MediaPlanServiceLine $line) => [
                'name' => $line->getName(),
                'unit' => $line->getUnit(),
                'quantity' => $line->getQuantity(),
                'price' => $line->getUnitPrice(),
                'total' => $line->getTotal(),
            ], $plan->getServiceLines()->toArray()),
            'discountPercent' => $plan->getDiscountPercent(),
            'placementSubtotal' => $plan->getPlacementSubtotal(),
            'discount' => $plan->getDiscountAmount(),
            'promotionSavings' => $plan->getPromotionSavings(),
            'placementTotal' => $plan->getPlacementTotal(),
            'servicesTotal' => $plan->getServicesTotal(),
            'total' => $plan->getTotal(),
            'createdAt' => $plan->getCreatedAt()?->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lead(Lead $lead): array
    {
        return [
            'id' => $lead->getId(),
            'status' => $lead->getStatus()->value,
            'statusLabel' => $lead->getStatus()->label(),
            'closed' => $lead->getStatus()->isClosed(),
            'createdAt' => $lead->getCreatedAt()?->format(\DATE_ATOM),
            'campaignId' => $lead->getMediaPlan()?->getClient() === $lead->getClient() ? $lead->getMediaPlan()?->getId() : null,
            'estimate' => $lead->getEstimate(),
            'comment' => $lead->getComment(),
            'items' => array_map(static fn (LeadItem $item) => [
                'structureId' => $item->getProduct()?->getId(),
                'title' => $item->getProductTitle(),
                'side' => $item->getSideName(),
                'from' => $item->getStartDate()->format('Y-m-d'),
                'to' => $item->getEndDate()->format('Y-m-d'),
                'monthlyPrice' => $item->getMonthlyPrice(),
                'total' => $item->getTotal(),
            ], $lead->getItems()->toArray()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(Payment $payment): array
    {
        $status = $payment->statusAt($this->clock->now());

        return [
            'id' => $payment->getId(),
            'title' => $payment->getTitle(),
            'amount' => (float) $payment->getAmount(),
            'dueDate' => $payment->getDueDate()?->format('Y-m-d'),
            'paidAt' => $payment->getPaidAt()?->format('Y-m-d'),
            'status' => $status->value,
            'statusLabel' => $status->label(),
            'campaignId' => $payment->getMediaPlan()?->getId(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function document(ClientDocument $document): array
    {
        return [
            'id' => $document->getId(),
            'type' => $document->getType()->value,
            'typeLabel' => $document->getType()->label(),
            'title' => $document->getTitle(),
            'fileName' => $document->getOriginalName(),
            'extension' => $document->getExtension(),
            'size' => $document->getSize(),
            'viewable' => $document->isViewable(),
            'comment' => $document->getComment(),
            'createdAt' => $document->getCreatedAt()?->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(PhotoReport $report): array
    {
        $product = $report->getProduct();

        return [
            'id' => $report->getId(),
            'title' => $report->getTitle(),
            'shotAt' => $report->getShotAt()?->format('Y-m-d'),
            'comment' => $report->getComment(),
            'structure' => null !== $product ? ['id' => $product->getId(), 'code' => $product->getSchemeNumber(), 'name' => $product->getName()] : null,
            'photos' => array_map(static fn (PhotoReportPhoto $photo) => ['id' => $photo->getId(), 'name' => $photo->getOriginalName()], $report->getPhotos()->toArray()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function campaignItem(MediaPlanItem $item): array
    {
        $product = $item->getProduct();
        $booking = $item->getBooking();
        $bookingStatus = $booking?->getStatusAt($this->clock->now());

        return [
            'structureId' => $product?->getId(),
            'code' => $product?->getSchemeNumber(),
            // as in the proposal the client got
            'name' => $item->getDisplayTitle(),
            'type' => $item->getSide()->getEffectiveProductType()?->getName(),
            'sizeLabel' => null !== $product ? ProductHelper::sizeLabel($product->getSize()) : null,
            'side' => $item->getSide()->getName(),
            'monthlyPrice' => $item->getMonthlyPrice(),
            'basePrice' => $item->getBasePrice(),
            'promotion' => $item->hasPromotionPrice() ? $item->getPromotionTitle() : null,
            'booking' => null !== $bookingStatus ? [
                'status' => $bookingStatus->value,
                'statusLabel' => $bookingStatus->label(),
                'from' => $booking->getStartDate()->format('Y-m-d'),
                'to' => $booking->getEndDate()->format('Y-m-d'),
                'expiresAt' => BookingStatus::Hold === $bookingStatus ? $booking->getExpiresAt()?->format(\DATE_ATOM) : null,
            ] : null,
        ];
    }

    /**
     * Where a campaign stands for the client, from its dates and the bookings made out of it.
     *
     * @return array{string, string}
     */
    private function campaignStatus(MediaPlan $plan): array
    {
        $now = $this->clock->now();
        $today = $now->setTime(0, 0);
        $statuses = [];
        foreach ($plan->getItems() as $item) {
            if (null !== $booking = $item->getBooking()) {
                $statuses[] = $booking->getStatusAt($now);
            }
        }
        $booked = array_filter($statuses, static fn (BookingStatus $s) => BookingStatus::Hold === $s || BookingStatus::Paid === $s);

        return match (true) {
            null !== $plan->getEndDate() && $plan->getEndDate() < $today => ['finished', 'Завершена'],
            [] === $booked => ['proposal', 'Предложение'],
            \in_array(BookingStatus::Hold, $booked, true) => ['hold', 'Бронь, ждёт оплаты'],
            null !== $plan->getStartMonth() && $plan->getStartMonth() <= $today => ['live', 'В эфире'],
            default => ['scheduled', 'Оплачена, ждёт старта'],
        };
    }
}
