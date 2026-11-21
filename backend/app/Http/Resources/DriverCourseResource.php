<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Parcel;
use App\Models\ParcelPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource unique d'API Livreur pour la fiche commande GoShop et la fiche colis GoColis
 * (utilisé à l'identique pour l'attribution automatique et l'assignation manuelle).
 */
class DriverCourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof Order) {
            return $this->formatOrder($this->resource);
        }

        /** @var Parcel $parcel */
        $parcel = $this->resource;

        return $this->formatParcel($parcel);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOrder(Order $order): array
    {
        $order->loadMissing(['merchant.user', 'client', 'items.product']);
        $isCash = $order->payment_method === 'cash';
        $totalClient = (int) round((float) $order->products_total + (float) $order->delivery_fee);

        return [
            'id' => $order->id,
            'course_type' => 'goshop',
            'status' => $order->status,
            'city_id' => $order->city_id,
            'courier_id' => $order->courier_id,
            'pickup_address' => $order->merchant !== null ? $order->merchant->address : 'Commerce partenaire',
            'pickup_lat' => $order->pickup_lat !== null ? (float) $order->pickup_lat : ($order->merchant?->lat !== null ? (float) $order->merchant->lat : null),
            'pickup_lng' => $order->pickup_lng !== null ? (float) $order->pickup_lng : ($order->merchant?->lng !== null ? (float) $order->merchant->lng : null),
            'delivery_address' => $order->delivery_address,
            'delivery_lat' => $order->delivery_lat !== null ? (float) $order->delivery_lat : null,
            'delivery_lng' => $order->delivery_lng !== null ? (float) $order->delivery_lng : null,
            'distance_km' => $order->distance_km !== null ? (float) $order->distance_km : null,
            'size' => null,
            'instructions' => $order->special_instructions,
            'special_instructions' => $order->special_instructions,
            'pickup_contact_name' => $order->merchant?->name,
            'pickup_contact_phone' => $order->merchant?->user?->phone,
            'delivery_contact_name' => $order->client?->name,
            'delivery_contact_phone' => $order->client?->phone,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'products_total' => (float) $order->products_total,
            'delivery_fee' => (float) $order->delivery_fee,
            'courier_amount' => (float) $order->courier_amount,
            'amount_to_collect' => $isCash ? $totalClient : 0,
            'cash_to_collect' => $isCash ? $totalClient : 0,
            'merchant' => $order->merchant ? [
                'id' => $order->merchant->id,
                'name' => $order->merchant->name,
                'address' => $order->merchant->address,
                'phone' => $order->merchant->user?->phone,
                'lat' => $order->merchant->lat !== null ? (float) $order->merchant->lat : null,
                'lng' => $order->merchant->lng !== null ? (float) $order->merchant->lng : null,
            ] : null,
            'client' => $order->client ? [
                'id' => $order->client->id,
                'name' => $order->client->name,
                'phone' => $order->client->phone,
            ] : null,
            'items' => $order->items->map(static function (OrderItem $item): array {
                $product = $item->product;

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'name' => $item->product_name_snapshot ?: ($product !== null ? $product->name : 'Article'),
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                ];
            })->values()->all(),
            'accepted_at' => $order->accepted_at?->toIso8601String(),
            'ready_at' => $order->ready_at?->toIso8601String(),
            'picked_up_at' => $order->picked_up_at?->toIso8601String(),
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatParcel(Parcel $parcel): array
    {
        $parcel->loadMissing(['sender', 'photos']);
        $isCash = $parcel->payment_method === 'cash';
        $totalClient = (int) $parcel->shipping_fee + (int) $parcel->insurance_amount;

        return [
            'id' => $parcel->id,
            'course_type' => 'gocolis',
            'status' => $parcel->status,
            'city_id' => $parcel->city_id,
            'courier_id' => $parcel->courier_id,
            'pickup_address' => $parcel->pickup_address,
            'pickup_lat' => $parcel->pickup_lat !== null ? (float) $parcel->pickup_lat : null,
            'pickup_lng' => $parcel->pickup_lng !== null ? (float) $parcel->pickup_lng : null,
            'delivery_address' => $parcel->delivery_address,
            'delivery_lat' => $parcel->delivery_lat !== null ? (float) $parcel->delivery_lat : null,
            'delivery_lng' => $parcel->delivery_lng !== null ? (float) $parcel->delivery_lng : null,
            'distance_km' => $parcel->distance_km !== null ? (float) $parcel->distance_km : null,
            'size' => $parcel->size,
            'size_label' => $parcel->size_label,
            'weight_kg' => $parcel->weight_kg !== null ? (float) $parcel->weight_kg : null,
            'instructions' => $parcel->description,
            'description' => $parcel->description,
            'pickup_contact_name' => $parcel->sender?->name,
            'pickup_contact_phone' => $parcel->sender?->phone,
            'delivery_contact_name' => $parcel->recipient_name,
            'delivery_contact_phone' => $parcel->recipient_phone,
            'recipient_name' => $parcel->recipient_name,
            'recipient_phone' => $parcel->recipient_phone,
            'payment_method' => $parcel->payment_method,
            'payment_status' => $parcel->payment_status,
            'shipping_fee' => (int) $parcel->shipping_fee,
            'insurance_amount' => (int) $parcel->insurance_amount,
            'courier_amount' => (int) $parcel->courier_amount,
            'amount_to_collect' => $isCash ? $totalClient : 0,
            'cash_to_collect' => $isCash ? $totalClient : 0,
            'sender' => $parcel->sender ? [
                'id' => $parcel->sender->id,
                'name' => $parcel->sender->name,
                'phone' => $parcel->sender->phone,
            ] : null,
            'photos' => $parcel->photos->map(static fn (ParcelPhoto $photo): array => [
                'id' => $photo->id,
                'type' => $photo->type,
                'signed_url' => $photo->signed_url,
                'created_at' => $photo->created_at?->toIso8601String(),
            ])->values()->all(),
            'picked_up_at' => $parcel->picked_up_at?->toIso8601String(),
            'delivered_at' => $parcel->delivered_at?->toIso8601String(),
            'created_at' => $parcel->created_at?->toIso8601String(),
        ];
    }
}
