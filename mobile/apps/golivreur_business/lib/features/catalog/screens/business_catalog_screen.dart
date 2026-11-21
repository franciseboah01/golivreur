import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:image_picker/image_picker.dart';
import '../../business/providers/business_provider.dart';

class BusinessCatalogScreen extends ConsumerStatefulWidget {
  const BusinessCatalogScreen({super.key});

  @override
  ConsumerState<BusinessCatalogScreen> createState() =>
      _BusinessCatalogScreenState();
}

class _BusinessCatalogScreenState extends ConsumerState<BusinessCatalogScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final schema = ref.read(businessCatalogSchemaProvider);
      if (!schema.isLoaded && !schema.isLoading) {
        ref.read(businessCatalogSchemaProvider.notifier).chargerSchema();
      }
    });
  }

  Future<void> _refreshAll() async {
    await Future.wait([
      ref.read(businessCatalogSchemaProvider.notifier).chargerSchema(),
      ref.read(businessCatalogProvider.notifier).chargerCatalogue(),
    ]);
  }

  Future<void> _openDynamicProductForm({BusinessProductItem? existing}) async {
    var schema = ref.read(businessCatalogSchemaProvider);
    if (!schema.isLoaded || schema.fields.isEmpty) {
      await ref.read(businessCatalogSchemaProvider.notifier).chargerSchema();
      schema = ref.read(businessCatalogSchemaProvider);
    }
    if (!mounted) return;

    if (schema.fields.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            schema.errorMessage ??
                'Aucun champ n\'est configuré pour votre type de commerce. Contactez l\'administration GoLivreur.',
          ),
          backgroundColor: GoLivreurTheme.erreur,
        ),
      );
      return;
    }

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
      ),
      builder: (_) => _DynamicProductFormSheet(
        schema: schema,
        existingProduct: existing,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final products = ref.watch(businessCatalogProvider);
    final schema = ref.watch(businessCatalogSchemaProvider);
    final addCta = schema.isFood ? 'Ajouter un plat' : 'Ajouter un produit';

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Mon Catalogue & Stock'),
        backgroundColor: Colors.white,
        elevation: 0.5,
        actions: [
          IconButton(
            icon: const Icon(Icons.add, color: GoLivreurTheme.primaire, size: 28),
            tooltip: addCta,
            onPressed: () => _openDynamicProductForm(),
          ),
        ],
      ),
      body: RefreshIndicator(
        color: const Color(0xFF2E75B6),
        onRefresh: _refreshAll,
        child: products.isEmpty
            ? ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 72),
                children: [
                  const Icon(
                    Icons.inventory_2_outlined,
                    size: 48,
                    color: GoLivreurTheme.texteSecondaire,
                  ),
                  const SizedBox(height: 12),
                  const Text(
                    'Aucun article dans votre catalogue.',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 15,
                      color: GoLivreurTheme.textePrincipal,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    schema.categoryName.isNotEmpty
                        ? 'Appuyez sur « $addCta » pour publier votre premier article (${schema.categoryName}).'
                        : 'Appuyez sur « $addCta » pour publier votre premier article sur GoShop.',
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      color: GoLivreurTheme.texteSecondaire,
                      fontSize: 13,
                    ),
                  ),
                ],
              )
            : ListView.separated(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 92),
                itemCount: products.length,
                separatorBuilder: (_, __) => const SizedBox(height: 10),
                itemBuilder: (context, index) {
                  final item = products[index];
                  return _buildProductRow(context, item, schema.isFood);
                },
              ),
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: GoLivreurTheme.primaire,
        foregroundColor: Colors.white,
        onPressed: () => _openDynamicProductForm(),
        icon: const Icon(Icons.add),
        label: Text(addCta, style: const TextStyle(fontWeight: FontWeight.bold)),
      ),
    );
  }

  Widget _buildProductRow(
    BuildContext context,
    BusinessProductItem item,
    bool isFood,
  ) {
    final subtitleParts = <String>[
      '${item.price.toInt()} F CFA',
      if (item.category != null && item.category!.trim().isNotEmpty)
        item.category!.trim(),
      if (item.quantity == null)
        'Stock : Illimité'
      else if (item.quantity! <= 0)
        'Rupture (0)'
      else
        'Stock : ${item.quantity}',
    ];

    return InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: () => _openDynamicProductForm(existing: item),
      child: Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: GoLivreurTheme.bordure),
        ),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(10),
              child: item.photoUrl != null && item.photoUrl!.isNotEmpty
                  ? Image.network(
                      item.photoUrl!,
                      width: 52,
                      height: 52,
                      fit: BoxFit.cover,
                      errorBuilder: (_, __, ___) => _productIconBox(item, isFood),
                    )
                  : _productIconBox(item, isFood),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    item.name,
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 14.5,
                      color: item.isAvailable
                          ? GoLivreurTheme.textePrincipal
                          : GoLivreurTheme.texteSecondaire,
                      decoration:
                          item.isAvailable ? null : TextDecoration.lineThrough,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    subtitleParts.join(' • '),
                    style: const TextStyle(
                      fontSize: 13,
                      color: GoLivreurTheme.texteSecondaire,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                  if (item.orderedAttributes.isNotEmpty) ...[
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 6,
                      runSpacing: 4,
                      children: item.orderedAttributes.take(4).map((attr) {
                        final displayVal = attr.value is bool
                            ? (attr.value == true ? 'Oui' : 'Non')
                            : '${attr.value}';
                        return Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 7,
                            vertical: 2,
                          ),
                          decoration: BoxDecoration(
                            color: GoLivreurTheme.fond,
                            borderRadius: BorderRadius.circular(6),
                            border: Border.all(color: GoLivreurTheme.bordure),
                          ),
                          child: Text(
                            '${attr.label} : $displayVal',
                            style: const TextStyle(
                              fontSize: 11,
                              color: GoLivreurTheme.textePrincipal,
                            ),
                            overflow: TextOverflow.ellipsis,
                          ),
                        );
                      }).toList(),
                    ),
                  ],
                  if (!item.isAvailable) ...[
                    const SizedBox(height: 4),
                    Text(
                      isFood
                          ? 'Épuisé en cuisine (Non commandable)'
                          : 'Rupture de stock (Non commandable)',
                      style: const TextStyle(
                        fontSize: 11,
                        color: GoLivreurTheme.erreur,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ],
                ],
              ),
            ),
            Column(
              children: [
                Switch(
                  value: item.isAvailable,
                  activeTrackColor: GoLivreurTheme.secondaire,
                  onChanged: (val) {
                    ref
                        .read(businessCatalogProvider.notifier)
                        .toggleStock(item.id);
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                        content: Text(
                          val
                              ? '${item.name} remis en stock.'
                              : '${item.name} marqué comme épuisé.',
                        ),
                        duration: const Duration(seconds: 1),
                      ),
                    );
                  },
                ),
                Text(
                  item.isAvailable ? 'En stock' : 'Épuisé',
                  style: TextStyle(
                    fontSize: 10,
                    fontWeight: FontWeight.bold,
                    color: item.isAvailable
                        ? GoLivreurTheme.secondaire
                        : GoLivreurTheme.texteSecondaire,
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _productIconBox(BusinessProductItem item, bool isFood) {
    return Container(
      width: 52,
      height: 52,
      decoration: BoxDecoration(
        color: item.isAvailable
            ? GoLivreurTheme.primaire.withValues(alpha: 0.12)
            : Colors.grey.shade200,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Icon(
        isFood ? Icons.restaurant : Icons.shopping_bag_outlined,
        color: item.isAvailable ? GoLivreurTheme.primaire : Colors.grey,
        size: 24,
      ),
    );
  }
}

/// Formulaire 100 % dynamique généré dans l'ordre exact des champs cochés par l'Admin
/// pour le Type de Commerce du commerçant connecté.
class _DynamicProductFormSheet extends ConsumerStatefulWidget {
  final MerchantCatalogSchema schema;
  final BusinessProductItem? existingProduct;

  const _DynamicProductFormSheet({
    required this.schema,
    this.existingProduct,
  });

  @override
  ConsumerState<_DynamicProductFormSheet> createState() =>
      _DynamicProductFormSheetState();
}

class _DynamicProductFormSheetState
    extends ConsumerState<_DynamicProductFormSheet> {
  final _formKey = GlobalKey<FormState>();
  final _picker = ImagePicker();

  final Map<String, TextEditingController> _controllers = {};
  final Map<String, String?> _selectValues = {};
  final Map<String, bool> _boolValues = {};

  XFile? _pickedPhotoFile;
  Uint8List? _pickedPhotoBytes;
  String? _existingPhotoUrl;
  bool _submitting = false;

  @override
  void initState() {
    super.initState();
    final existing = widget.existingProduct;
    _existingPhotoUrl = existing?.photoUrl;

    for (final field in widget.schema.fields) {
      final initial = _extractInitialValue(existing, field);

      if (field.fieldType == 'boolean') {
        _boolValues[field.code] =
            initial == true || initial == 1 || initial?.toString() == 'true';
      } else if (field.fieldType == 'select' && field.options.isNotEmpty) {
        final str = initial?.toString();
        _selectValues[field.code] =
            (str != null && field.options.contains(str)) ? str : null;
      } else if (field.fieldType != 'image') {
        _controllers[field.code] = TextEditingController(
          text: initial != null ? '$initial' : '',
        );
        if (field.code == 'quantity') {
          _boolValues['_unlimited_stock'] = initial == null || '$initial'.trim().isEmpty;
        }
      }
    }
  }

  dynamic _extractInitialValue(
    BusinessProductItem? existing,
    CatalogFieldDefinition field,
  ) {
    if (existing == null) return null;
    return switch (field.code) {
      'name' => existing.name,
      'description' => existing.description,
      'price' => existing.price > 0 ? existing.price.toInt().toString() : '',
      'quantity' => existing.quantity?.toString(),
      'category' => existing.category,
      'photo' => existing.photoUrl,
      _ => existing.attributes[field.code],
    };
  }

  @override
  void dispose() {
    for (final ctrl in _controllers.values) {
      ctrl.dispose();
    }
    super.dispose();
  }

  Future<void> _pickPhoto(ImageSource source) async {
    try {
      final file = await _picker.pickImage(
        source: source,
        maxWidth: 1024,
        maxHeight: 1024,
        imageQuality: 78,
        requestFullMetadata: false,
      );
      if (file == null) return;
      final bytes = await file.readAsBytes();
      if (!mounted) return;
      setState(() {
        _pickedPhotoFile = file;
        _pickedPhotoBytes = bytes;
      });
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Impossible d\'accéder à la photo. Vérifiez les autorisations.'),
        ),
      );
    }
  }

  Future<void> _submit() async {
    if (_submitting) return;
    if (!_formKey.currentState!.validate()) return;

    // Vérifier si un champ image est obligatoire
    for (final field in widget.schema.fields) {
      if (field.fieldType == 'image' && field.isRequired) {
        final hasPhoto = _pickedPhotoFile != null ||
            (_existingPhotoUrl != null && _existingPhotoUrl!.isNotEmpty);
        if (!hasPhoto) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text('Le champ « ${field.label} » est obligatoire.'),
              backgroundColor: GoLivreurTheme.erreur,
            ),
          );
          return;
        }
      }
    }

    setState(() => _submitting = true);

    final coreFields = <String, dynamic>{};
    final dynamicAttributes = <String, dynamic>{};

    // N'envoyer STRICTEMENT que les champs présents dans le schéma du Type de Commerce
    for (final field in widget.schema.fields) {
      final code = field.code;
      if (field.fieldType == 'image') {
        continue;
      }

      dynamic value;
      if (field.fieldType == 'boolean') {
        value = _boolValues[code] ?? false;
      } else if (field.fieldType == 'select' && field.options.isNotEmpty) {
        value = _selectValues[code];
      } else {
        final raw = _controllers[code]?.text.trim() ?? '';
        if (raw.isEmpty) {
          value = null;
        } else if (field.fieldType == 'currency') {
          value = num.tryParse(raw.replaceAll(' ', '')) ?? 0;
        } else if (field.fieldType == 'number') {
          value = int.tryParse(raw.replaceAll(' ', ''));
        } else {
          value = raw;
        }
      }

      if (code == 'quantity' && (_boolValues['_unlimited_stock'] ?? false)) {
        coreFields['quantity'] = null;
        continue;
      }

      if (value == null && !field.isRequired && widget.existingProduct == null) {
        continue;
      }

      if (field.isCore) {
        coreFields[code] = value;
      } else {
        dynamicAttributes[code] = value;
      }
    }

    final (ok, errorMsg) =
        await ref.read(businessCatalogProvider.notifier).saveDynamicProduct(
              productId: widget.existingProduct?.id,
              coreFields: coreFields,
              dynamicAttributes: dynamicAttributes,
              photoFile: _pickedPhotoFile,
            );

    if (!mounted) return;
    setState(() => _submitting = false);

    if (ok) {
      Navigator.of(context).pop();
      final isEdit = widget.existingProduct != null;
      final label = widget.schema.isFood ? 'Plat' : 'Produit';
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            isEdit ? '$label mis à jour avec succès.' : '$label ajouté au catalogue !',
          ),
          backgroundColor: GoLivreurTheme.secondaire,
        ),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(errorMsg ?? 'Impossible d\'enregistrer cet article.'),
          backgroundColor: GoLivreurTheme.erreur,
        ),
      );
    }
  }

  Widget _buildDynamicField(CatalogFieldDefinition field) {
    final labelText = field.isRequired ? '${field.label} *' : field.label;

    if (field.fieldType == 'image') {
      return _buildPhotoField(field, labelText);
    }

    if (field.fieldType == 'boolean') {
      return Container(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: GoLivreurTheme.bordure),
        ),
        child: SwitchListTile(
          title: Text(
            labelText,
            style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
          ),
          value: _boolValues[field.code] ?? false,
          activeThumbColor: GoLivreurTheme.secondaire,
          onChanged: (v) => setState(() => _boolValues[field.code] = v),
        ),
      );
    }

    if (field.fieldType == 'select' && field.options.isNotEmpty) {
      return DropdownButtonFormField<String>(
        key: ValueKey('field_${field.code}'),
        initialValue: _selectValues[field.code],
        isExpanded: true,
        decoration: InputDecoration(
          labelText: labelText,
          hintText: field.placeholder ?? 'Sélectionner...',
        ),
        items: field.options
            .map((opt) => DropdownMenuItem<String>(value: opt, child: Text(opt)))
            .toList(),
        onChanged: (val) => setState(() => _selectValues[field.code] = val),
        validator: (val) {
          if (field.isRequired && (val == null || val.trim().isEmpty)) {
            return 'Le champ « ${field.label} » est obligatoire.';
          }
          return null;
        },
      );
    }

    final isNumeric =
        field.fieldType == 'currency' || field.fieldType == 'number';
    final isTextarea = field.fieldType == 'textarea';

    if (field.code == 'quantity') {
      final isUnlimited = _boolValues['_unlimited_stock'] ?? false;
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Material(
            color: GoLivreurTheme.fond,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(12),
              side: const BorderSide(color: GoLivreurTheme.bordure),
            ),
            clipBehavior: Clip.antiAlias,
            child: SwitchListTile(
              title: Text(
                field.label,
                style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
              ),
              subtitle: Text(
                isUnlimited
                    ? 'Stock illimité (aucune limite de quantité)'
                    : 'Quantité limitée (0 = indisponible)',
                style: const TextStyle(fontSize: 11.5),
              ),
              value: isUnlimited,
              activeThumbColor: GoLivreurTheme.secondaire,
              onChanged: (v) {
                setState(() {
                  _boolValues['_unlimited_stock'] = v;
                  if (v) {
                    _controllers[field.code]?.clear();
                  }
                });
              },
            ),
          ),
          if (!isUnlimited) ...[
            const SizedBox(height: 10),
            TextFormField(
              key: ValueKey('field_${field.code}'),
              controller: _controllers[field.code],
              keyboardType: TextInputType.number,
              decoration: InputDecoration(
                labelText: labelText,
                hintText: field.placeholder ?? 'Ex: 20 (0 = indisponible)',
              ),
              validator: (val) {
                final trimmed = (val ?? '').trim();
                if (trimmed.isEmpty) {
                  return 'Saisissez une quantité ou activez « Stock illimité ».';
                }
                final parsed = int.tryParse(trimmed.replaceAll(' ', ''));
                if (parsed == null || parsed < 0) {
                  return 'Saisissez un nombre entier positif ou 0.';
                }
                return null;
              },
            ),
          ],
        ],
      );
    }

    return TextFormField(
      key: ValueKey('field_${field.code}'),
      controller: _controllers[field.code],
      keyboardType: isNumeric
          ? TextInputType.number
          : (isTextarea ? TextInputType.multiline : TextInputType.text),
      minLines: isTextarea ? 2 : 1,
      maxLines: isTextarea ? 4 : 1,
      decoration: InputDecoration(
        labelText: labelText,
        hintText: field.placeholder,
        suffixText: field.fieldType == 'currency' ? 'F CFA' : null,
      ),
      validator: (val) {
        final trimmed = (val ?? '').trim();
        if (field.isRequired && trimmed.isEmpty) {
          return 'Le champ « ${field.label} » est obligatoire.';
        }
        if (trimmed.isEmpty) return null;
        if (field.fieldType == 'currency') {
          final parsed = num.tryParse(trimmed.replaceAll(' ', ''));
          if (parsed == null || parsed < 100) {
            return 'Saisissez un montant valide (min. 100 F CFA).';
          }
        } else if (field.fieldType == 'number') {
          final parsed = int.tryParse(trimmed.replaceAll(' ', ''));
          if (parsed == null || parsed < 0) {
            return 'Saisissez un nombre entier positif.';
          }
        }
        return null;
      },
    );
  }

  Widget _buildPhotoField(CatalogFieldDefinition field, String labelText) {
    final hasPreview = _pickedPhotoBytes != null ||
        (_existingPhotoUrl != null && _existingPhotoUrl!.isNotEmpty);

    return Container(
      key: ValueKey('field_${field.code}'),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: GoLivreurTheme.fond,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: GoLivreurTheme.bordure),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            labelText,
            style: const TextStyle(
              fontSize: 13.5,
              fontWeight: FontWeight.w600,
              color: GoLivreurTheme.textePrincipal,
            ),
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              if (_pickedPhotoBytes != null)
                ClipRRect(
                  borderRadius: BorderRadius.circular(10),
                  child: Image.memory(
                    _pickedPhotoBytes!,
                    width: 64,
                    height: 64,
                    fit: BoxFit.cover,
                  ),
                )
              else if (_existingPhotoUrl != null &&
                  _existingPhotoUrl!.isNotEmpty)
                ClipRRect(
                  borderRadius: BorderRadius.circular(10),
                  child: Image.network(
                    _existingPhotoUrl!,
                    width: 64,
                    height: 64,
                    fit: BoxFit.cover,
                    errorBuilder: (_, __, ___) => const Icon(Icons.image, size: 40),
                  ),
                )
              else
                Container(
                  width: 64,
                  height: 64,
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: GoLivreurTheme.bordure),
                  ),
                  child: const Icon(
                    Icons.add_a_photo_outlined,
                    color: GoLivreurTheme.texteSecondaire,
                  ),
                ),
              const SizedBox(width: 12),
              Expanded(
                child: Wrap(
                  spacing: 8,
                  runSpacing: 6,
                  children: [
                    OutlinedButton.icon(
                      onPressed: () => _pickPhoto(ImageSource.camera),
                      icon: const Icon(Icons.camera_alt_outlined, size: 18),
                      label: const Text('Caméra'),
                    ),
                    OutlinedButton.icon(
                      onPressed: () => _pickPhoto(ImageSource.gallery),
                      icon: const Icon(Icons.photo_library_outlined, size: 18),
                      label: const Text('Galerie'),
                    ),
                    if (hasPreview)
                      TextButton(
                        onPressed: () => setState(() {
                          _pickedPhotoFile = null;
                          _pickedPhotoBytes = null;
                          _existingPhotoUrl = null;
                        }),
                        child: const Text(
                          'Retirer',
                          style: TextStyle(color: GoLivreurTheme.erreur),
                        ),
                      ),
                  ],
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isEdit = widget.existingProduct != null;
    final title = isEdit
        ? (widget.schema.isFood ? 'Modifier le plat' : 'Modifier le produit')
        : (widget.schema.isFood ? 'Ajouter un plat' : 'Ajouter un produit');
    final submitText = isEdit
        ? 'Enregistrer les modifications'
        : (widget.schema.isFood ? 'Publier le plat' : 'Publier le produit');

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(
          left: 20,
          right: 20,
          top: 16,
          bottom: MediaQuery.of(context).viewInsets.bottom + 20,
        ),
        child: ConstrainedBox(
          constraints: BoxConstraints(
            maxHeight: MediaQuery.of(context).size.height * 0.85,
          ),
          child: SingleChildScrollView(
            child: Form(
              key: _formKey,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Center(
                    child: Container(
                      width: 40,
                      height: 4,
                      decoration: BoxDecoration(
                        color: GoLivreurTheme.bordure,
                        borderRadius: BorderRadius.circular(2),
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    title,
                    style: const TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.bold,
                      color: GoLivreurTheme.textePrincipal,
                    ),
                  ),
                  if (widget.schema.categoryName.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(
                      'Type de commerce : ${widget.schema.categoryName}',
                      style: const TextStyle(
                        fontSize: 12.5,
                        color: GoLivreurTheme.texteSecondaire,
                      ),
                    ),
                  ],
                  const SizedBox(height: 16),

                  // Génération 100 % dynamique dans l'ordre exact défini par l'Admin
                  for (var i = 0; i < widget.schema.fields.length; i++) ...[
                    _buildDynamicField(widget.schema.fields[i]),
                    if (i < widget.schema.fields.length - 1)
                      const SizedBox(height: 12),
                  ],

                  const SizedBox(height: 20),
                  SizedBox(
                    height: 50,
                    child: ElevatedButton(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: GoLivreurTheme.primaire,
                        foregroundColor: Colors.white,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                        ),
                      ),
                      onPressed: _submitting ? null : _submit,
                      child: _submitting
                          ? const SizedBox(
                              width: 22,
                              height: 22,
                              child: CircularProgressIndicator(
                                strokeWidth: 2.5,
                                color: Colors.white,
                              ),
                            )
                          : Text(
                              submitText,
                              style: const TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 15.5,
                              ),
                            ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
