import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/di/injector.dart';
import '../cubit/catalog_cubit.dart';
import '../cubit/catalog_state.dart';
import 'food_detail_page.dart';

class FoodCataloguePage extends StatelessWidget {
  const FoodCataloguePage({super.key});

  @override
  Widget build(BuildContext context) {
    return BlocProvider<CatalogCubit>(
      create: (_) => sl<CatalogCubit>()..load(),
      child: const _CatalogueView(),
    );
  }
}

class _CatalogueView extends StatelessWidget {
  const _CatalogueView();

  @override
  Widget build(BuildContext context) {
    return Column(
      children: <Widget>[
        Padding(
          padding: const EdgeInsets.all(12),
          child: TextField(
            decoration: const InputDecoration(
              hintText: 'Search Nigerian foods…',
              prefixIcon: Icon(Icons.search),
              border: OutlineInputBorder(),
            ),
            onSubmitted: (value) => context.read<CatalogCubit>().search(value),
          ),
        ),
        Expanded(
          child: BlocBuilder<CatalogCubit, CatalogState>(
            builder: (context, state) {
              if (state.status == CatalogStatus.loading) {
                return const Center(child: CircularProgressIndicator());
              }
              if (state.status == CatalogStatus.error) {
                return Center(child: Text(state.error ?? 'Something went wrong.'));
              }
              if (state.foods.isEmpty) {
                return const Center(child: Text('No foods found.'));
              }
              return ListView.separated(
                itemCount: state.foods.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (context, index) {
                  final food = state.foods[index];
                  return ListTile(
                    leading: CircleAvatar(
                      backgroundImage: food.primaryImage != null ? NetworkImage(food.primaryImage!) : null,
                      child: food.primaryImage == null ? const Text('🍲') : null,
                    ),
                    title: Text(food.name),
                    subtitle: Text(food.region.replaceAll('_', ' ')),
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(builder: (_) => FoodDetailPage(slug: food.slug)),
                    ),
                  );
                },
              );
            },
          ),
        ),
      ],
    );
  }
}
