import 'package:flutter/material.dart';

import '../../../../core/di/injector.dart';
import '../../domain/entities/reviews_entities.dart';
import '../../domain/repositories/reviews_repository.dart';

/// The mobile reviews screen for a subject: its rating summary, the review list,
/// and a sheet to write your own. Every call goes through the Reviews context.
class ReviewsPage extends StatefulWidget {
  const ReviewsPage({super.key, this.subjectType = 'vendor', this.subjectId = 'vendor-1'});

  final String subjectType;
  final String subjectId;

  @override
  State<ReviewsPage> createState() => _ReviewsPageState();
}

class _ReviewsPageState extends State<ReviewsPage> {
  final ReviewsRepository _repo = sl<ReviewsRepository>();

  RatingSummaryView? _summary;
  List<ReviewView> _reviews = <ReviewView>[];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final summary = await _repo.summary(widget.subjectType, widget.subjectId);
    final reviews = await _repo.forSubject(widget.subjectType, widget.subjectId);
    if (!mounted) return;
    setState(() {
      summary.fold((_) => _summary = null, (s) => _summary = s);
      reviews.fold((_) => _reviews = <ReviewView>[], (r) => _reviews = r);
      _loading = false;
    });
  }

  Future<void> _vote(ReviewView review) async {
    final result = await _repo.vote(review.id, true);
    if (!mounted) return;
    result.fold(
      (failure) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(failure.message))),
      (_) => _load(),
    );
  }

  Future<void> _writeReview() async {
    final created = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _WriteReviewSheet(
        repo: _repo,
        subjectType: widget.subjectType,
        subjectId: widget.subjectId,
      ),
    );
    if (created == true) {
      await _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Reviews')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _writeReview,
        icon: const Icon(Icons.rate_review),
        label: const Text('Write a review'),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: <Widget>[
                  if (_summary != null) _SummaryCard(summary: _summary!),
                  const SizedBox(height: 12),
                  if (_reviews.isEmpty)
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: 32),
                      child: Center(child: Text('No reviews yet — be the first.')),
                    )
                  else
                    ..._reviews.map((ReviewView r) => _ReviewCard(review: r, onHelpful: () => _vote(r))),
                ],
              ),
            ),
    );
  }
}

class _StarRow extends StatelessWidget {
  const _StarRow({required this.value});

  final double value;

  @override
  Widget build(BuildContext context) {
    final rounded = value.round();
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: List<Widget>.generate(
        5,
        (int i) => Icon(
          i < rounded ? Icons.star : Icons.star_border,
          size: 18,
          color: const Color(0xFFF5A623),
        ),
      ),
    );
  }
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({required this.summary});

  final RatingSummaryView summary;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: <Widget>[
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(summary.average.toStringAsFixed(1), style: Theme.of(context).textTheme.headlineMedium),
                _StarRow(value: summary.average),
                Text('${summary.count} reviews · ${summary.verifiedCount} verified'),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _ReviewCard extends StatelessWidget {
  const _ReviewCard({required this.review, required this.onHelpful});

  final ReviewView review;
  final VoidCallback onHelpful;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Row(
              children: <Widget>[
                _StarRow(value: review.rating.toDouble()),
                const SizedBox(width: 8),
                if (review.verifiedPurchase)
                  const Chip(
                    label: Text('Verified', style: TextStyle(fontSize: 11)),
                    backgroundColor: Color(0xFFE5F5E9),
                    visualDensity: VisualDensity.compact,
                  ),
              ],
            ),
            if (review.title != null && review.title!.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(review.title!, style: const TextStyle(fontWeight: FontWeight.bold)),
              ),
            if (review.body != null && review.body!.isNotEmpty)
              Padding(padding: const EdgeInsets.only(top: 4), child: Text(review.body!)),
            if (review.ownerResponse != null)
              Container(
                margin: const EdgeInsets.only(top: 8),
                padding: const EdgeInsets.all(8),
                color: const Color(0xFFF6F8FA),
                child: Text('Owner: ${review.ownerResponse!.body}'),
              ),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: onHelpful,
                icon: const Icon(Icons.thumb_up_alt_outlined, size: 16),
                label: Text('Helpful (${review.helpfulYes})'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _WriteReviewSheet extends StatefulWidget {
  const _WriteReviewSheet({required this.repo, required this.subjectType, required this.subjectId});

  final ReviewsRepository repo;
  final String subjectType;
  final String subjectId;

  @override
  State<_WriteReviewSheet> createState() => _WriteReviewSheetState();
}

class _WriteReviewSheetState extends State<_WriteReviewSheet> {
  int _rating = 5;
  final TextEditingController _title = TextEditingController();
  final TextEditingController _body = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _title.dispose();
    _body.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _busy = true);
    final result = await widget.repo.submit(
      widget.subjectType,
      widget.subjectId,
      _rating,
      _title.text.isEmpty ? null : _title.text,
      _body.text.isEmpty ? null : _body.text,
    );
    if (!mounted) return;
    setState(() => _busy = false);
    result.fold(
      (failure) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(failure.message))),
      (review) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              review.status == 'published' ? 'Thanks — your review is live.' : 'Thanks — awaiting moderation.',
            ),
          ),
        );
        Navigator.of(context).pop(true);
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: 16,
        right: 16,
        top: 16,
        bottom: MediaQuery.of(context).viewInsets.bottom + 16,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text('Write a review', style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 8),
          DropdownButtonFormField<int>(
            value: _rating,
            decoration: const InputDecoration(labelText: 'Rating'),
            items: <int>[5, 4, 3, 2, 1]
                .map((int n) => DropdownMenuItem<int>(value: n, child: Text('$n star${n == 1 ? '' : 's'}')))
                .toList(),
            onChanged: (int? value) => setState(() => _rating = value ?? 5),
          ),
          TextField(controller: _title, decoration: const InputDecoration(labelText: 'Title')),
          TextField(
            controller: _body,
            decoration: const InputDecoration(labelText: 'Your experience'),
            maxLines: 3,
          ),
          const SizedBox(height: 12),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Text('Submit review'),
            ),
          ),
        ],
      ),
    );
  }
}
