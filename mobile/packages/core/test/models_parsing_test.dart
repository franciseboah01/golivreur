import 'package:flutter_test/flutter_test.dart';
import 'package:golivreur_core/models/modeles.dart';

void main() {
  group('Resilient JSON parsing (MySQL/PostgreSQL DECIMAL strings & ints)', () {
    test('OrderModel.fromJson parse sans crash les DECIMAL en String et booleens 0/1', () {
      final order = OrderModel.fromJson({
        'id': 12,
        'status': 'pending',
        'products_total': '3500',
        'delivery_fee': '500.00',
        'payment_method': 'cash',
        'payment_status': 'pending_cash',
        'confirmation_locked': 0,
        'delivery_address': 'Sirasso Centre',
        'delivery_lat': '9.4580000',
        'delivery_lng': '-6.2080000',
        'merchant': {
          'id': 3,
          'name': 'Maquis Chez Tantie',
          'category': 'restaurant',
          'address': 'Marché',
          'lat': '9.4590000',
          'lng': '-6.2070000',
          'prep_time_minutes': '20',
          'is_open': 1,
          'average_rating': '4.80',
        },
        'items': [
          {
            'id': 55,
            'product_id': 7,
            'product_name_snapshot': 'Attiéké Poisson Braisé',
            'quantity': '2',
            'unit_price': '1750.00',
          },
        ],
        'created_at': '2026-11-20T18:47:00.000000Z',
      });

      expect(order.id, 12);
      expect(order.productsTotal, 3500.0);
      expect(order.deliveryFee, 500.0);
      expect(order.totalClient, 4000.0);
      expect(order.deliveryLat, closeTo(9.458, 0.0001));
      expect(order.deliveryLng, closeTo(-6.208, 0.0001));
      expect(order.merchant?.isOpen, isTrue);
      expect(order.merchant?.averageRating, closeTo(4.8, 0.01));
      expect(order.items.single.unitPrice, 1750.0);
      expect(order.items.single.quantity, 2);
    });

    test('ParcelModel.fromJson parse sans crash les DECIMAL en String et photos signed_url', () {
      final parcel = ParcelModel.fromJson({
        'id': 1,
        'status': 'pending',
        'size': 'moyen',
        'declared_value': '25000',
        'has_insurance': 1,
        'insurance_amount': '750',
        'recipient_name': 'Koné Mariam',
        'recipient_phone': '0709000001',
        'delivery_address': 'Commerce',
        'pickup_lat': '9.4581234',
        'pickup_lng': '-6.2085678',
        'delivery_lat': '9.4610000',
        'delivery_lng': '-6.2100000',
        'shipping_fee': '1200',
        'payment_method': 'wave',
        'sender_code_locked': 0,
        'recipient_code_locked': 0,
        'photos': [
          {
            'id': 9,
            'type': 'parcel',
            'photo_url': '',
            'signed_url': 'https://golivreur.alwaysdata.net/media/parcel-photos/1.jpg?sig=abc',
          },
        ],
        'created_at': '2026-11-20T18:35:00.000000Z',
      });

      expect(parcel.id, 1);
      expect(parcel.sizeLabel, 'Moyen (max 5 kg)');
      expect(parcel.declaredValue, 25000.0);
      expect(parcel.hasInsurance, isTrue);
      expect(parcel.insuranceAmount, 750.0);
      expect(parcel.shippingFee, 1200.0);
      expect(parcel.pickupLat, closeTo(9.4581234, 0.0001));
      expect(parcel.pickupLng, closeTo(-6.2085678, 0.0001));
      expect(parcel.photoColis?.photoUrl, contains('media/parcel-photos/1.jpg'));
    });
  });
}
