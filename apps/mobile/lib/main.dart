import 'package:flutter/material.dart';
import 'package:flutter_dotenv/flutter_dotenv.dart';

import 'app.dart';
import 'core/di/injector.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Load runtime configuration, then wire the dependency graph.
  await dotenv.load(fileName: '.env');
  await configureDependencies();

  runApp(const EruoFoodApp());
}
