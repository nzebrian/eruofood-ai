import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/repositories/nutrition_repository.dart';

/// Health profile form.
class HealthProfilePage extends StatefulWidget {
  const HealthProfilePage({super.key});

  @override
  State<HealthProfilePage> createState() => _HealthProfilePageState();
}

class _HealthProfilePageState extends State<HealthProfilePage> {
  final NutritionRepository _repo = sl<NutritionRepository>();
  final TextEditingController _weight = TextEditingController(text: '80');
  final TextEditingController _height = TextEditingController(text: '175');
  final TextEditingController _age = TextEditingController(text: '30');
  String _gender = 'male';
  String _activity = 'moderate';
  String _goal = 'maintain';
  bool _saving = false;
  String? _message;

  @override
  void initState() {
    super.initState();
    _repo.profile().then((result) {
      result.fold((_) {}, (profile) {
        if (profile != null && mounted) {
          setState(() {
            _weight.text = profile.weightKg.toString();
            _height.text = profile.heightCm.toString();
            _age.text = profile.age.toString();
            _gender = profile.gender;
            _activity = profile.activityLevel;
            _goal = profile.goal;
          });
        }
      });
    });
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _message = null;
    });
    final result = await _repo.saveProfile(<String, dynamic>{
      'weight_kg': double.tryParse(_weight.text) ?? 0,
      'height_cm': double.tryParse(_height.text) ?? 0,
      'age': int.tryParse(_age.text) ?? 0,
      'gender': _gender,
      'activity_level': _activity,
      'goal': _goal,
    });
    if (!mounted) {
      return;
    }
    setState(() {
      _saving = false;
      _message = result.fold((f) => f.message, (_) => 'Profile saved.');
    });
  }

  @override
  void dispose() {
    _weight.dispose();
    _height.dispose();
    _age.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Health profile')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: <Widget>[
          TextField(
            controller: _weight,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'Weight (kg)'),
          ),
          TextField(
            controller: _height,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'Height (cm)'),
          ),
          TextField(
            controller: _age,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'Age'),
          ),
          _dropdown('Gender', _gender, const <String>['male', 'female', 'other'], (v) => _gender = v),
          _dropdown('Activity', _activity,
              const <String>['sedentary', 'light', 'moderate', 'active', 'very_active'], (v) => _activity = v),
          _dropdown('Goal', _goal,
              const <String>['lose_weight', 'maintain', 'gain_weight', 'gain_muscle'], (v) => _goal = v),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _saving ? null : _save,
            child: Text(_saving ? 'Saving…' : 'Save profile'),
          ),
          if (_message != null) ...<Widget>[
            const SizedBox(height: 12),
            Text(_message!),
          ],
        ],
      ),
    );
  }

  Widget _dropdown(String label, String value, List<String> options, void Function(String) onChanged) {
    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: DropdownButtonFormField<String>(
        initialValue: value,
        decoration: InputDecoration(labelText: label),
        items: options
            .map((o) => DropdownMenuItem<String>(value: o, child: Text(o.replaceAll('_', ' '))))
            .toList(),
        onChanged: (v) => setState(() {
          if (v != null) {
            onChanged(v);
          }
        }),
      ),
    );
  }
}
